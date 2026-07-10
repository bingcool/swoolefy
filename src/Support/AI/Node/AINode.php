<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Swoolefy\Support\AI\Node;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use Swoolefy\Support\AI\Builder\AINodeBuilder;
use Swoolefy\Support\AI\Stream\StreamBridge;
use Swoolefy\Support\Neuron\NeuronFactory;
use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\RunContext;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\Node\AbstractNode;
use Swoolefy\Support\Workflow\Node\ConfigurableTimeoutNodeInterface;
use Swoolefy\Support\Workflow\State\WorkflowState;

/**
 * AI 工作流节点 —— 在 DAG 中封装 Neuron Agent 的 chat / structured / stream。
 *
 * ---------------------------------------------------------------------------
 * 定位
 * ---------------------------------------------------------------------------
 *
 *   WorkflowEngine → AbstractNode::run() → AINode::execute()
 *                                              │
 *                    ┌─────────────────────────┼─────────────────────────┐
 *                    ▼                         ▼                         ▼
 *               executor(mock)          Agent::stream()           Agent::structured()
 *                                                                      / chat()
 *
 * 推荐用 {@see AINodeBuilder} 构造（Fluent DSL），避免手写 config 数组：
 *
 *   AINode::make('ai_decision')
 *       ->agent(OrderDecisionAgent::class)
 *       ->structured(OrderDecisionDto::class, outputKey: 'decision')
 *       ->promptKey('prompt')
 *       ->timeout(120)
 *       ->build($neuronFactory);
 *
 * ---------------------------------------------------------------------------
 * 执行路径优先级（互斥选择，自上而下）
 * ---------------------------------------------------------------------------
 *
 *   1. config['executor'] 可调用     — 单测 / HTTP mock，完全跳过 LLM
 *   2. config['stream'] === true     — Agent::stream()，token 经 StreamBridge 推送
 *   3. config['structured'] 为类名   — Agent::structured()，强类型 DTO
 *   4. config['agent'] 为类名        — Agent::chat()，纯文本
 *   否则抛 WorkflowException
 *
 * 硬约束：stream 与 structured **不能同时开启**（语义冲突）。
 *
 * ---------------------------------------------------------------------------
 * 输出写入
 * ---------------------------------------------------------------------------
 *
 *   - 结果写入 state[outputKey]（默认 'output'；对象会先 get_object_vars）
 *   - NodeExecutionResult::success 再带一份同名键 + nodeId，供下游 / 事件使用
 *   - metrics.nodeType = 'ai'，便于 Tracing / Metrics 插件区分
 *
 * ---------------------------------------------------------------------------
 * Agent 创建
 * ---------------------------------------------------------------------------
 *
 *   agentFactory 注入 > neuronFactory->create() > new $agentClass()
 *   会话记忆由 Agent::chatHistory() 自行声明；仅当 agentOptions 显式传 chatHistory 时由 Factory 覆盖。
 *
 * @see AINodeBuilder
 * @see docs/SwoolefyAI.md §4.2
 */
final class AINode extends AbstractNode implements ConfigurableTimeoutNodeInterface
{
    /**
     * 单测注入点：完全接管 Agent 构造（签名与 NeuronFactory::create 对齐）。
     *
     * @var (callable(class-string, WorkflowState, array): Agent)|null
     */
    private $agentFactory;

    /**
     * @param array<string, mixed> $config
     *        常用键：agent / structured / stream / executor / outputKey / promptKey /
     *        timeout / mcpServers / provider / middleware 等（后几项透传给 NeuronFactory）
     * @param callable|null        $agentFactory   优先于 neuronFactory（测试用）
     * @param NeuronFactory|null   $neuronFactory  生产装配 Provider / Tool / Middleware
     */
    public function __construct(
        string $nodeId,
        private readonly array $config,
        ?callable $agentFactory = null,
        private readonly ?NeuronFactory $neuronFactory = null,
    ) {
        parent::__construct($nodeId);
        $this->agentFactory = $agentFactory;
    }

    /**
     * Fluent 入口：返回 Builder，最终 build() 得到本节点实例。
     */
    public static function make(string $nodeId): AINodeBuilder
    {
        return AINodeBuilder::make($nodeId);
    }

    /**
     * 节点核心逻辑（由 AbstractNode::run() 在生命周期钩子之间调用）。
     *
     * {@inheritdoc}
     *
     * @throws WorkflowException 配置非法或缺少 agent/executor
     */
    public function execute(RunContext $ctx, WorkflowState $state): NodeExecutionResult
    {
        // stream 产出增量文本；structured 产出完整 DTO —— 同节点互斥，避免语义含糊。
        if (($this->config['stream'] ?? false) === true && isset($this->config['structured'])) {
            throw new WorkflowException("AINode {$this->nodeId} cannot enable stream and structured together");
        }

        // 下游条件边 / 节点通常读 state[outputKey]；默认 'output'。
        $outputKey = (string) ($this->config['outputKey'] ?? 'output');
        // 预留：未来可将 tool_call 等事件塞进 NodeExecutionResult.events 供 EventBus 广播。
        $events = [];

        // --- 按优先级选择执行路径 ---
        if (isset($this->config['executor']) && is_callable($this->config['executor'])) {
            // mock：签名 (RunContext, WorkflowState): mixed，可直接返回 DTO / 数组 / 字符串。
            $output = ($this->config['executor'])($ctx, $state);
        } elseif (($this->config['stream'] ?? false) === true) {
            $output = $this->invokeStreamAgent($state);
        } elseif (isset($this->config['structured']) && is_string($this->config['structured'])) {
            $output = $this->invokeStructuredAgent($state, $this->config['structured']);
        } elseif (isset($this->config['agent']) && is_string($this->config['agent'])) {
            $output = $this->invokeChatAgent($state);
        } else {
            throw new WorkflowException(
                "AINode {$this->nodeId} requires executor, stream, structured or agent configuration",
            );
        }

        // 对象（如 DTO）拆成关联数组写入 State，便于序列化快照与条件边表达式读取字段。
        if (is_object($output)) {
            $state->set($outputKey, get_object_vars($output));
        } else {
            $state->set($outputKey, $output);
        }

        // success 的 data 会再 merge 进 state；此处显式带回 outputKey，保证与 set 一致。
        return NodeExecutionResult::success([
            $outputKey => $state->get($outputKey),
            'nodeId' => $this->nodeId,
        ], events: $events, metrics: ['nodeType' => 'ai']);
    }

    /**
     * 节点级超时（秒）。
     *
     * 供 WorkflowEngine TimeoutGuard 读取；0 表示不覆盖，回退到 RunContext / 全局默认。
     */
    public function configuredTimeoutSeconds(): int
    {
        return (int) ($this->config['timeout'] ?? 0);
    }

    /**
     * 流式路径：消费 Agent::stream() 事件流，拼接全文并推送 token。
     *
     * 仅处理 TextChunk；其它 chunk（reasoning / tool_call 等）当前忽略，
     * 需要时可在此扩展并 StreamBridge::emit 对应事件名。
     */
    private function invokeStreamAgent(WorkflowState $state): string
    {
        $agentClass = $this->requireAgentClass();
        $agent = $this->createAgent($agentClass, $state);
        $prompt = $this->buildPrompt($state);
        $handler = $agent->stream(new UserMessage($prompt));

        $fullText = '';
        foreach ($handler->events() as $event) {
            if ($event instanceof TextChunk) {
                $fullText .= $event->content;
                // 推给当前请求绑定的 SSE/WS Sink（无 Sink 时 StreamBridge 为空操作）。
                StreamBridge::emit('token', [
                    'nodeId' => $this->nodeId,
                    'content' => $event->content,
                ]);
            }
        }

        // 完整文本仍写入 state[outputKey]，供非流式下游节点使用。
        return $fullText;
    }

    /**
     * 普通对话路径：Agent::chat() → 助手消息文本。
     */
    private function invokeChatAgent(WorkflowState $state): string
    {
        $agentClass = $this->requireAgentClass();
        $agent = $this->createAgent($agentClass, $state);
        $prompt = $this->buildPrompt($state);

        return $agent->chat(new UserMessage($prompt))->getMessage()->getContent();
    }

    /**
     * 结构化输出路径：Agent::structured() → DTO 实例。
     *
     * @param class-string $structuredClass Neuron / 业务 DTO 类名（须可被 structured 映射）
     */
    private function invokeStructuredAgent(WorkflowState $state, string $structuredClass): object
    {
        $agentClass = $this->requireAgentClass();
        $agent = $this->createAgent($agentClass, $state);
        $prompt = $this->buildPrompt($state);

        /** @var object $output */
        $output = $agent->structured(new UserMessage($prompt), $structuredClass);

        return $output;
    }

    /**
     * 校验并返回 Agent 类名。
     *
     * @return class-string<Agent>
     *
     * @throws WorkflowException
     */
    private function requireAgentClass(): string
    {
        $agentClass = $this->config['agent'] ?? null;
        if (!is_string($agentClass) || !is_subclass_of($agentClass, Agent::class)) {
            throw new WorkflowException("AINode {$this->nodeId} agent must extend Neuron Agent");
        }

        return $agentClass;
    }

    /**
     * 创建 Agent 实例。
     *
     * 优先级：
     *   1. 构造注入的 agentFactory（单测）
     *   2. NeuronFactory::create（生产：Provider + Tool + Middleware + 可选 ChatHistory）
     *   3. new $agentClass()（无 Factory 时的最小回退；依赖 Agent 自身 provider()）
     *
     * 注意：整个 $this->config 作为 agentOptions 传入 Factory，
     * 因此 Builder 上的 mcp / provider / middleware 等键会一并透传。
     *
     * @param class-string<Agent> $agentClass
     */
    private function createAgent(string $agentClass, WorkflowState $state): Agent
    {
        if ($this->agentFactory !== null) {
            return ($this->agentFactory)($agentClass, $state, $this->config);
        }

        if ($this->neuronFactory !== null) {
            return $this->neuronFactory->create($agentClass, $state, $this->config);
        }

        // 会话记忆由 Agent::chatHistory() 决定，不再由节点强制注入。
        return new $agentClass();
    }

    /**
     * 从 WorkflowState 解析本轮 UserMessage 文本。
     *
     * 优先级：
     *   1. state[promptKey]（默认键 'prompt'）非空字符串
     *   2. 存在 orderId → 订单风控默认提示（兼容 Order 示例）
     *   3. state['query'] 非空字符串（兼容 Research 等）
     *   4. 通用兜底文案
     */
    private function buildPrompt(WorkflowState $state): string
    {
        $promptKey = (string) ($this->config['promptKey'] ?? 'prompt');
        $prompt = $state->get($promptKey);

        if (is_string($prompt) && $prompt !== '') {
            return $prompt;
        }

        $orderId = $state->get('orderId');
        if ($orderId !== null) {
            return "Review order {$orderId} and return structured decision.";
        }

        $query = $state->get('query');
        if (is_string($query) && $query !== '') {
            return $query;
        }

        return 'Process workflow input and return structured decision.';
    }
}
