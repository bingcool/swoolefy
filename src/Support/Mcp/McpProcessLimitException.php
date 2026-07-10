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
