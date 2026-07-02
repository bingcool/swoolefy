<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Exception;

/**
 * Workflow Run 并发限流异常。
 *
 * 由 {@see \Swoolefy\Support\Workflow\Plugin\Builtin\RateLimitPlugin} 在 run.start 抛出；
 * HTTP 层建议映射为 429 Too Many Requests。
 */
final class WorkflowRateLimitException extends WorkflowException
{
}
