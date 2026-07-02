<?php

declare(strict_types=1);

namespace Swoolefy\Support\Mcp;

use Swoolefy\Support\Workflow\Exception\WorkflowException;

/**
 * 本地 MCP 子进程并发超限异常。
 *
 * 由 {@see McpProcessRunner::acquire()} 抛出；Caller 可捕获后降级为 disabled 或排队重试。
 */
final class McpProcessLimitException extends WorkflowException
{
}
