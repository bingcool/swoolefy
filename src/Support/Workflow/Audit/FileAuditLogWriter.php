<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Audit;

use Swoolefy\Core\Log\LogManager;
use Swoolefy\Support\Workflow\WorkflowConfig;

/**
 * 工作流审计日志写入器（基于框架日志组件）。
 */
final class FileAuditLogWriter implements AuditLogWriterInterface
{
    public function __construct(
        private readonly string $logComponent = '',
    ) {
    }

    /** {@inheritdoc} */
    public function write(string $event, array $context): void
    {
        $loggerType = $this->logComponent !== ''
            ? $this->logComponent
            : WorkflowConfig::load()->logComponent();
        $logger = LogManager::getInstance()->getLogger($loggerType);
        if ($logger === null) {
            return;
        }

        $logger->info($event, [
            'event' => $event,
            'context' => $context,
            'at' => date('c'),
        ]);
    }
}
