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

use Swoolefy\Support\AI\Builder\AINodeBuilder;
use Swoolefy\Support\Neuron\NeuronFactory;
use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\RunContext;
use Swoolefy\Support\Workflow\Node\AbstractNode;
use Swoolefy\Support\Workflow\State\WorkflowState;

/**
 * Structured Output 专用节点 —— 薄封装 {@see AINode} 的 structured 路径。
 *
 * ---------------------------------------------------------------------------
 * 定位
 * ---------------------------------------------------------------------------
 *
 * 语义上等价于：
 *
 *   AINode::make($nodeId)
 *       ->agent(...)
 *       ->structured(Dto::class, outputKey: ...)
 *       ->build($neuronFactory);
 *
 * 单独成类是为了在 DAG / 文档中一眼看出「本节点产出强类型 DTO」，
 * 而不是通用 chat/stream 节点。实际执行全部委托内部 {@see AINode}。
 *
 * ---------------------------------------------------------------------------
 * 构造时从 $extra 识别的键
 * ---------------------------------------------------------------------------
 *
 *   - outputKey    string     写入 state 的键，默认 'output'
 *   - agent        class-string  Neuron Agent 子类（structured 路径仍需要）
 *   - executor     callable   mock 覆盖（签名同 AINode executor）
 *
 * 会话记忆请在业务 Agent::chatHistory() 中声明（见 ChatHistoryFactory）。
 * 其它 AINode 配置若需要，可直接用 AINodeBuilder，不必经本类。
 *
 * ---------------------------------------------------------------------------
 * 输出
 * ---------------------------------------------------------------------------
 *
 * 与 AINode structured 路径相同：DTO 经 get_object_vars 写入 state[outputKey]，
 * 供条件边 `$state->dto(XxxDto::class)` / 表达式读取字段。
 *
 * @see AINode
 * @see AINodeBuilder::structured()
 * @see docs/SwoolefyAI.md §4.5
 */
final class StructuredOutputNode extends AbstractNode
{
    /** 真正执行 LLM / mock 的委托节点。 */
    private readonly AINode $delegate;

    /**
     * @param class-string         $dtoClass       Structured Output DTO 类（传给 Agent::structured）
     * @param array<string, mixed> $extra          见类文档「构造时从 $extra 识别的键」
     * @param NeuronFactory|null   $neuronFactory  注入到内部 AINode；null 时 Agent 靠自身 provider()
     */
    public function __construct(
        string $nodeId,
        string $dtoClass,
        array $extra = [],
        ?NeuronFactory $neuronFactory = null,
    ) {
        parent::__construct($nodeId);

        // 与 AINode 默认一致；显式传入便于业务把结果写到 'decision' 等语义化键。
        $outputKey = (string) ($extra['outputKey'] ?? 'output');

        // 强制走 structured 路径；Builder 会设置 config['structured'] = $dtoClass。
        $builder = AINodeBuilder::make($nodeId)
            ->structured($dtoClass, outputKey: $outputKey);

        // Agent 类：structured 仍依赖具体 Agent（instructions / tools / provider）。
        if (isset($extra['agent']) && is_string($extra['agent'])) {
            $builder->agent($extra['agent']);
        }

        // 单测 / 演示：跳过 LLM，直接返回 DTO 或兼容结构。
        if (isset($extra['executor']) && is_callable($extra['executor'])) {
            $builder->executor($extra['executor']);
        }

        // build() 产出 AINode；neuronFactory 用于 create(Agent)。
        $this->delegate = $builder->build($neuronFactory);
    }

    /**
     * 原样委托 {@see AINode::execute()}（含 stream/structured 互斥等校验）。
     *
     * {@inheritdoc}
     */
    public function execute(RunContext $ctx, WorkflowState $state): NodeExecutionResult
    {
        return $this->delegate->execute($ctx, $state);
    }
}
