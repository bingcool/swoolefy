<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Audit;

use Swoolefy\Support\SupportLog;

/**
 * 工作流审计日志写入器（基于框架日志组件）。
 */
final class FileAuditLogWriter implements AuditLogWriterInterface
{
    /** {@inheritdoc} */
    public function write(string $event, array $context): void
    {
        SupportLog::info('workflow_audit', $event, [
            'event' => $event,
            'context' => $context,
            'at' => date('c'),
        ]);
    }
}
