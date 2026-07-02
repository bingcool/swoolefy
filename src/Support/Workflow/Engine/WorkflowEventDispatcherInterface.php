<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Engine;

/** 工作流对外事件分发（SSE/WebSocket），与 Plugin 钩子分离。 */
interface WorkflowEventDispatcherInterface
{
    /**
     * 发布对外可观测事件。
     *
     * @param array<string, mixed> $payload 事件载荷
     */
    public function publish(string $event, array $payload): void;
}
