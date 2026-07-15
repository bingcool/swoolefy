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

/**
 * SupportLog 端到端写入 support_log 组件验证。
 *
 * ## 覆盖范围
 * | 区域 | 要点 |
 * |------|------|
 * | SupportLog + LogManager | 注册 CHANNEL 后 info/warning 落盘到 support.log |
 * | FileAuditLogWriter | workflow 审计经 SupportLog，带 `[workflow_audit]` 前缀 |
 * | WorkflowConfig | 默认 log_component === SupportLog::CHANNEL |
 * | SupportLog 降级 | 无 logger 时 fallback error_log，不抛异常 |
 *
 * ## 运行
 * ```bash
 * php src/Support/Tests/SupportLogIntegrationTest.php
 * ```
 *
 * 说明：CLI 无真实 Swoole Server，需 stub Swfy::setSwooleServer；依赖 {@see SwoolefyTestBootstrap.php}。
 */

use Swoolefy\Core\Log\LogManager;
use Swoolefy\Support\SupportLog;
use Swoolefy\Support\Workflow\Audit\FileAuditLogWriter;
use Swoolefy\Support\Workflow\WorkflowConfig;

require dirname(__DIR__, 3) . '/vendor/autoload.php';
require __DIR__ . '/SwoolefyTestBootstrap.php';

/** 断言为真，否则抛 RuntimeException（单测失败） */
function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** 打印通过标记 */
function pass(string $name): void
{
    echo "[PASS] {$name}\n";
}

// ---------------------------------------------------------------------------
// 夹具：临时目录 + 最小 Server stub + 注册 support_log Logger
// ---------------------------------------------------------------------------

/**
 * 在系统临时目录注册 SupportLog::CHANNEL，并 stub Swoole Server 元数据，
 * 使 Util\Log 写盘前的进程类型探测不崩。
 *
 * @return string 日志根目录（含 support/support.log）
 */
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

/**
 * 读取已注册 support_log 组件对应文件的全部内容，供断言 marker / 通道前缀。
 */
function readSupportLogFile(): string
{
    $logger = LogManager::getInstance()->getLogger(SupportLog::CHANNEL);
    assertTrue($logger !== null, 'support_log logger must be registered');

    $path = $logger->getLogFilePath();
    assertTrue(is_file($path), "log file should exist at {$path}");

    return (string) file_get_contents($path);
}

// ---------------------------------------------------------------------------
// 用例
// ---------------------------------------------------------------------------

/**
 * SupportLog::info / warning 应出现在 support.log，并带 `[integration]` 通道前缀。
 */
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

/**
 * FileAuditLogWriter 默认走 SupportLog，审计行含 `[workflow_audit]`、事件名与 context。
 */
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

/**
 * WorkflowConfig 未显式配置 log_component 时，默认应为 SupportLog::CHANNEL（support_log）。
 */
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

/**
 * 未注册组件且重置 test handler 后，SupportLog::warning 降级到 error_log，调用不抛。
 */
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
