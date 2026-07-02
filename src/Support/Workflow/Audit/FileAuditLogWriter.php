<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Audit;

/**
 * 文件审计日志 —— 默认写入 storage/logs/workflow_audit.log。
 */
final class FileAuditLogWriter implements AuditLogWriterInterface
{
    public function __construct(
        private readonly string $filePath = '',
    ) {
    }

    /** {@inheritdoc} */
    public function write(string $event, array $context): void
    {
        $path = $this->filePath !== ''
            ? $this->filePath
            : (defined('RUNTIME_PATH') ? RUNTIME_PATH : sys_get_temp_dir()) . '/workflow_audit.log';

        $line = json_encode([
            'event' => $event,
            'context' => $context,
            'at' => date('c'),
        ], JSON_UNESCAPED_UNICODE);

        if ($line === false) {
            return;
        }

        @file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
