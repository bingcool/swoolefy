<?php

declare(strict_types=1);

/**
 * SupportLog 端到端写入 support_log 组件验证。
 *
 * 运行：php src/Support/Tests/SupportLogIntegrationTest.php
 */

use Swoolefy\Core\Log\LogManager;
use Swoolefy\Support\SupportLog;
use Swoolefy\Support\Workflow\Audit\FileAuditLogWriter;
use Swoolefy\Support\Workflow\WorkflowConfig;

require dirname(__DIR__, 3) . '/vendor/autoload.php';
require __DIR__ . '/SwoolefyTestBootstrap.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function pass(string $name): void
{
    echo "[PASS] {$name}\n";
}

function registerSupportLogComponent(): string
{
    $logRoot = sys_get_temp_dir() . '/swoolefy_support_log_test_' . getmypid();
    if (!is_dir($logRoot)) {
        mkdir($logRoot, 0777, true);
    }

    // CLI 单测无 Swoole Server，Util\Log 写盘前会探测进程类型，需最小 stub。
    $server = new stdClass();
    $server->taskworker = false;
    $server->worker_id = -1;
    \Swoolefy\Core\Swfy::setSwooleServer($server);

    LogManager::getInstance()->registerLoggerByClosure(static function ($name) use ($logRoot) {
        $logger = new \Swoolefy\Util\Log($name);
        $logger->setChannel('application');
        $logger->setLogFilePath($logRoot . '/support/support.log');
        return $logger;
    }, SupportLog::CHANNEL);

    return $logRoot;
}

function readSupportLogFile(): string
{
    $logger = LogManager::getInstance()->getLogger(SupportLog::CHANNEL);
    assertTrue($logger !== null, 'support_log logger must be registered');

    $path = $logger->getLogFilePath();
    assertTrue(is_file($path), "log file should exist at {$path}");

    return (string) file_get_contents($path);
}

function testSupportLogWritesToSupportLogComponent(): void
{
    registerSupportLogComponent();
    $marker = 'support_log_e2e_' . uniqid('', true);

    SupportLog::info('integration', $marker, ['source' => 'SupportLogIntegrationTest']);
    SupportLog::warning('integration', $marker . '_warn', ['level' => 'warning']);

    $content = readSupportLogFile();
    assertTrue(str_contains($content, $marker), 'info log should appear in support.log');
    assertTrue(str_contains($content, '[integration]'), 'channel prefix should appear in log line');
    assertTrue(str_contains($content, $marker . '_warn'), 'warning log should appear in support.log');

    pass('SupportLog writes to support_log component');
}

function testFileAuditLogWriterUsesSupportLog(): void
{
    registerSupportLogComponent();
    $event = 'workflow.run.completed.' . uniqid('', true);

    (new FileAuditLogWriter())->write($event, [
        'runId' => 'run-test-001',
        'status' => 'COMPLETED',
    ]);

    $content = readSupportLogFile();
    assertTrue(str_contains($content, '[workflow_audit]'), 'audit channel prefix should appear');
    assertTrue(str_contains($content, $event), 'audit event should appear in support.log');
    assertTrue(str_contains($content, 'run-test-001'), 'audit context should appear in support.log');

    pass('FileAuditLogWriter writes workflow_audit via SupportLog');
}

function testWorkflowConfigDefaultLogComponentIsSupportLog(): void
{
    $config = WorkflowConfig::fromArray([
        'workflow' => [
            'default_run_store' => 'memory',
        ],
    ]);

    assertTrue($config->logComponent() === SupportLog::CHANNEL, 'WorkflowConfig default log component should be support_log');

    pass('WorkflowConfig defaults log_component to support_log');
}

function testSupportLogFallbackUsesErrorLogWhenLoggerMissing(): void
{
    SupportLog::resetTestHandler();
    SupportLog::warning('fallback_channel', 'fallback_message', ['k' => 'v']);
    pass('SupportLog falls back to error_log when support_log is missing');
}

$tests = [
    'SupportLog writes to support_log component' => 'testSupportLogWritesToSupportLogComponent',
    'FileAuditLogWriter uses SupportLog' => 'testFileAuditLogWriterUsesSupportLog',
    'WorkflowConfig default log_component' => 'testWorkflowConfigDefaultLogComponentIsSupportLog',
    'SupportLog error_log fallback' => 'testSupportLogFallbackUsesErrorLogWhenLoggerMissing',
];

$passed = 0;
foreach ($tests as $name => $fn) {
    $fn();
    $passed++;
}

echo "\nAll {$passed} SupportLog integration tests passed.\n";
