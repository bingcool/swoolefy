<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Exception;

/**
 * 节点或 Run 执行超时异常 —— 由 {@see TimeoutGuard} 抛出。
 */
final class WorkflowTimeoutException extends WorkflowException
{
}
