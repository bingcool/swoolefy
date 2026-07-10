<?php

declare(strict_types=1);

namespace Test\Module\Agent\Middleware;

use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowState;

/**
 * 演示用 Middleware：记录 before/after 调用次数与 Node 类名。
 *
 * 用于验证 NeuronFactory 通过 agentOptions 挂载的 Middleware 在 Agent 执行周期中生效。
 *
 * @see https://docs.neuron-ai.dev/agent/middleware
 */
final class RecordingMiddleware implements WorkflowMiddleware
{
    public int $beforeCount = 0;

    public int $afterCount = 0;

    /** @var list<string> */
    public array $beforeNodes = [];

    /** @var list<string> */
    public array $afterNodes = [];

    public function before(NodeInterface $node, Event $event, WorkflowState $state): void
    {
        $this->beforeCount++;
        $this->beforeNodes[] = $node::class;
    }

    public function after(NodeInterface $node, Event $result, WorkflowState $state): void
    {
        $this->afterCount++;
        $this->afterNodes[] = $node::class;
    }

    /** @return array{beforeCount: int, afterCount: int, beforeNodes: list<string>, afterNodes: list<string>} */
    public function snapshot(): array
    {
        return [
            'beforeCount' => $this->beforeCount,
            'afterCount' => $this->afterCount,
            'beforeNodes' => $this->beforeNodes,
            'afterNodes' => $this->afterNodes,
        ];
    }
}
