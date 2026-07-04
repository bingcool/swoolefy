<?php

declare(strict_types=1);

namespace Swoolefy\Support\AI\Node;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use Swoolefy\Support\AI\Builder\AINodeBuilder;
use Swoolefy\Support\AI\Stream\StreamBridge;
use Swoolefy\Support\Neuron\Memory\MemoryFactoryInterface;
use Swoolefy\Support\Neuron\NeuronFactory;
use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\RunContext;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\Node\AbstractNode;
use Swoolefy\Support\Workflow\State\WorkflowState;

/**
 * AI 工作流节点 —— 封装 Neuron Agent structured / stream / chat 能力。
 *
 * 执行路径（优先级）：
 *   1. executor 可调用（测试/mock）
 *   2. stream + agent → Agent::stream()，token 经 StreamBridge 推送
 *   3. structured + agent → Agent::structured()
 *   4. agent → Agent::chat()
 */
final class AINode extends AbstractNode
{
    /** @var (callable(class-string, WorkflowState, array): Agent)|null */
    private $agentFactory;

    /** @param array<string, mixed> $config */
    public function __construct(
        string $nodeId,
        private readonly array $config,
        private readonly ?MemoryFactoryInterface $memoryFactory = null,
        ?callable $agentFactory = null,
        private readonly ?NeuronFactory $neuronFactory = null,
    ) {
        parent::__construct($nodeId);
        $this->agentFactory = $agentFactory;
    }

    public static function make(string $nodeId): AINodeBuilder
    {
        return AINodeBuilder::make($nodeId);
    }

    /** {@inheritdoc} */
    public function execute(RunContext $ctx, WorkflowState $state): NodeExecutionResult
    {
        if (($this->config['stream'] ?? false) === true && isset($this->config['structured'])) {
            throw new WorkflowException("AINode {$this->nodeId} cannot enable stream and structured together");
        }

        $outputKey = (string) ($this->config['outputKey'] ?? 'output');
        $events = [];

        if (isset($this->config['executor']) && is_callable($this->config['executor'])) {
            $output = ($this->config['executor'])($ctx, $state);
        } elseif (($this->config['stream'] ?? false) === true) {
            $output = $this->invokeStreamAgent($state);
        } elseif (isset($this->config['structured']) && is_string($this->config['structured'])) {
            $output = $this->invokeStructuredAgent($state, $this->config['structured']);
        } elseif (isset($this->config['agent']) && is_string($this->config['agent'])) {
            $output = $this->invokeChatAgent($state);
        } else {
            throw new WorkflowException("AINode {$this->nodeId} requires executor, stream, structured or agent configuration");
        }

        if (is_object($output)) {
            $state->set($outputKey, get_object_vars($output));
        } else {
            $state->set($outputKey, $output);
        }

        return NodeExecutionResult::success([
            $outputKey => $state->get($outputKey),
            'nodeId' => $this->nodeId,
        ], events: $events, metrics: ['nodeType' => 'ai']);
    }

    /** 节点配置的超时秒数（供 WorkflowEngine TimeoutGuard 使用）。 */
    public function configuredTimeoutSeconds(): int
    {
        return (int) ($this->config['timeout'] ?? 0);
    }

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
                $payload = [
                    'nodeId' => $this->nodeId,
                    'content' => $event->content,
                ];
                StreamBridge::emit('token', $payload);
            }
        }

        return $fullText;
    }

    private function invokeChatAgent(WorkflowState $state): string
    {
        $agentClass = $this->requireAgentClass();
        $agent = $this->createAgent($agentClass, $state);
        $prompt = $this->buildPrompt($state);

        return $agent->chat(new UserMessage($prompt))->getMessage()->getContent();
    }

    /** @param class-string $structuredClass */
    private function invokeStructuredAgent(WorkflowState $state, string $structuredClass): object
    {
        $agentClass = $this->requireAgentClass();
        $agent = $this->createAgent($agentClass, $state);
        $prompt = $this->buildPrompt($state);

        /** @var object $output */
        $output = $agent->structured(new UserMessage($prompt), $structuredClass);

        return $output;
    }

    /** @return class-string<Agent> */
    private function requireAgentClass(): string
    {
        $agentClass = $this->config['agent'] ?? null;
        if (!is_string($agentClass) || !is_subclass_of($agentClass, Agent::class)) {
            throw new WorkflowException("AINode {$this->nodeId} agent must extend Neuron Agent");
        }

        return $agentClass;
    }

    private function createAgent(string $agentClass, WorkflowState $state): Agent
    {
        if ($this->agentFactory !== null) {
            return ($this->agentFactory)($agentClass, $state, $this->config);
        }

        if ($this->neuronFactory !== null) {
            return $this->neuronFactory->create($agentClass, $state, $this->config);
        }

        // 会话记忆由 Agent::chatHistory() 决定，不再由节点强制注入
        return new $agentClass();
    }

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
