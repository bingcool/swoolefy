<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Exception;

/**
 * Workflow 权限不足异常。
 *
 * 由 {@see \Swoolefy\Support\Workflow\Plugin\Builtin\PermissionPlugin} 在 run.start 抛出；
 * HTTP 层建议映射为 403 Forbidden。
 */
final class WorkflowPermissionException extends WorkflowException
{
}
