<?php

declare(strict_types=1);

namespace Swoolefy\Support\AI\Node;

use Swoolefy\Support\AI\Builder\AINodeBuilder;
use Swoolefy\Support\Neuron\Memory\MemoryFactoryInterface;
use Swoolefy\Support\Neuron\NeuronFactory;
use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\RunContext;
use Swoolefy\Support\Workflow\Node\AbstractNode;
use Swoolefy\Support\Workflow\State\WorkflowState;

/**
 * Structured Output 专用节点 —— 薄封装 {@see AINode} structured 路径。
 *
 * 等价于 AINode::make()->structured(Dto::class)->build()，便于 DSL 语义清晰。
 */
final class StructuredOutputNode extends AbstractNode
{
    private readonly AINode $delegate;

    /**
     * @param class-string          $dtoClass  Structured Output DTO 类
     * @param array<string, mixed>  $extra     额外 AINode 配置（memory、agent 等）
     */
    public function __construct(
        string $nodeId,
        string $dtoClass,
        array $extra = [],
        ?MemoryFactoryInterface $memoryFactory = null,
        ?NeuronFactory $neuronFactory = null,
    ) {
        parent::__construct($nodeId);

        $outputKey = (string) ($extra['outputKey'] ?? 'output');
        $builder = AINodeBuilder::make($nodeId)
            ->structured($dtoClass, outputKey: $outputKey);

        if (isset($extra['agent']) && is_string($extra['agent'])) {
            $builder->agent($extra['agent']);
        }
        if (($extra['memory'] ?? false) === true) {
            $builder->memory(threadIdKey: (string) ($extra['threadIdKey'] ?? 'sessionId'));
        }
        if (isset($extra['executor']) && is_callable($extra['executor'])) {
            $builder->executor($extra['executor']);
        }

        $this->delegate = $builder->build($memoryFactory, $neuronFactory);
    }

    /** {@inheritdoc} */
    public function execute(RunContext $ctx, WorkflowState $state): NodeExecutionResult
    {
        return $this->delegate->execute($ctx, $state);
    }
}
