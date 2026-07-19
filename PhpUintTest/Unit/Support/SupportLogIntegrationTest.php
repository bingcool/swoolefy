<?php

declare(strict_types=1);

namespace PhpUintTest\Unit\Support;

use Swoolefy\Core\Log\LogManager;
use Swoolefy\Core\Swfy;
use Swoolefy\Support\SupportLog;
use Swoolefy\Support\Workflow\Audit\FileAuditLogWriter;
use Swoolefy\Support\Workflow\WorkflowConfig;
use PhpUintTest\TestCase;
use Swoolefy\Util\Log;
use stdClass;

/**
 * SupportLog 端到端写入 support_log 组件验证。
 */
final class SupportLogIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 3) . '/src/Support/Tests/SwoolefyTestBootstrap.php';
    }

    protected function tearDown(): void
    {
        SupportLog::resetTestHandler();
        parent::tearDown();
    }

    /**
     * @return string 日志根目录（含 support/support.log）
     */
    private function registerSupportLogComponent(): string
    {
        $logRoot = sys_get_temp_dir() . '/swoolefy_support_log_test_' . getmypid();
        if (!is_dir($logRoot)) {
            mkdir($logRoot, 0777, true);
        }

        $server = new stdClass();
        $server->taskworker = false;
        $server->worker_id = -1;
        Swfy::setSwooleServer($server);

        LogManager::getInstance()->registerLoggerByClosure(static function ($name) use ($logRoot) {
            $logger = new Log($name);
            $logger->setChannel('application');
            $logger->setLogFilePath($logRoot . '/support/support.log');

            return $logger;
        }, SupportLog::CHANNEL);

        return $logRoot;
    }

    private function readSupportLogFile(): string
    {
        $logger = LogManager::getInstance()->getLogger(SupportLog::CHANNEL);
        $this->assertNotNull($logger);

        $path = $logger->getLogFilePath();
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * 验证：SupportLog info/warning 写入已注册的 support_log 组件文件，含频道与标记内容。
     */
    public function testSupportLogWritesToSupportLogComponent(): void
    {
        $this->registerSupportLogComponent();
        $marker = 'support_log_e2e_' . uniqid('', true);

        SupportLog::info('integration', $marker, ['source' => 'SupportLogIntegrationTest']);
        SupportLog::warning('integration', $marker . '_warn', ['level' => 'warning']);

        $content = $this->readSupportLogFile();
        $this->assertStringContainsString($marker, $content);
        $this->assertStringContainsString('[integration]', $content);
        $this->assertStringContainsString($marker . '_warn', $content);
    }

    /**
     * 验证：FileAuditLogWriter 将工作流审计事件写入 support_log，含 [workflow_audit] 前缀。
     */
    public function testFileAuditLogWriterUsesSupportLog(): void
    {
        $this->registerSupportLogComponent();
        $event = 'workflow.run.completed.' . uniqid('', true);

        (new FileAuditLogWriter())->write($event, [
            'runId' => 'run-test-001',
            'status' => 'COMPLETED',
        ]);

        $content = $this->readSupportLogFile();
        $this->assertStringContainsString('[workflow_audit]', $content);
        $this->assertStringContainsString($event, $content);
        $this->assertStringContainsString('run-test-001', $content);
    }

    /**
     * 验证：WorkflowConfig 未显式指定时，默认日志组件为 SupportLog::CHANNEL。
     */
    public function testWorkflowConfigDefaultLogComponentIsSupportLog(): void
    {
        $config = WorkflowConfig::fromArray([
            'workflow' => [
                'default_run_store' => 'memory',
            ],
        ]);

        $this->assertSame(SupportLog::CHANNEL, $config->logComponent());
    }

    /**
     * 验证：未注册 Logger 时 SupportLog 降级写入 error_log，调用不抛异常。
     */
    public function testSupportLogFallbackUsesErrorLogWhenLoggerMissing(): void
    {
        SupportLog::resetTestHandler();
        SupportLog::warning('fallback_channel', 'fallback_message', ['k' => 'v']);
        $this->assertTrue(true);
    }
}
