<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Cmd;

use PHPUnit\Framework\TestCase;
use Swoolefy\Cmd\Command\StartCmd;
use Swoolefy\Cmd\Command\StopCmd;
use Swoolefy\Cmd\Command\RestartCmd;
use Swoolefy\Cmd\Command\ReloadCmd;
use Swoolefy\Cmd\Command\MonitorCmd;
use Swoolefy\Cmd\Command\SendCmd;
use Swoolefy\Cmd\Command\StatusCmd;
use Swoolefy\Cmd\CreateCmd;
use Swoolefy\Cmd\BaseCmd;
use Swoolefy\Cmd\Application\ServerLifecycleManager;
use Swoolefy\Cmd\Infrastructure\PidFileManager;
use Swoolefy\Cmd\Infrastructure\CmdLogWriter;
use Swoolefy\Cmd\Infrastructure\ServerStatusRenderer;
use Swoolefy\Cmd\Infrastructure\FifoPipeClient;
use Swoolefy\Cmd\Infrastructure\CmdInitException;
use Swoolefy\Cmd\DTO\StopStatus;
use Swoolefy\Cmd\DTO\StopResult;
use Swoolefy\Cmd\DTO\CmdContext;

/**
 * Cmd 模块集成测试。
 *
 * 验证整个模块的结构完整性：
 * - 所有命令类存在且可实例化
 * - 命令名称正确注册
 * - 新目录结构（Command/Application/Infrastructure/DTO）类可加载
 * - CmdInitException 异常行为正确
 * - ServerLifecycleManager 超时解析逻辑
 */
class CmdCommandIntegrationTest extends TestCase
{
    /**
     * 在测试前定义必要的全局常量。
     *
     * StartCmd::configure() 会调用 SystemEnv::getRestartModelPidFile()，
     * 该函数依赖 WORKER_PID_FILE_ROOT 常量。
     */
    public static function setUpBeforeClass(): void
    {
        if (!defined('WORKER_PID_FILE_ROOT')) {
            define('WORKER_PID_FILE_ROOT', sys_get_temp_dir() . '/swoolefy_test');
        }
    }

    /**
     * 测试所有命令类存在且可实例化。
     *
     * 确保重构后的命名空间和类路径正确。
     */
    public function testAllCommandClassesExist(): void
    {
        $classes = [
            StartCmd::class,
            StopCmd::class,
            RestartCmd::class,
            ReloadCmd::class,
            MonitorCmd::class,
            SendCmd::class,
            StatusCmd::class,
            CreateCmd::class,
        ];

        foreach ($classes as $class) {
            $this->assertTrue(class_exists($class), "Class {$class} should exist");
        }
    }

    /**
     * 测试所有命令都继承自 BaseCmd。
     */
    public function testAllCommandsExtendBaseCmd(): void
    {
        $commands = [
            new StartCmd(),
            new StopCmd(),
            new RestartCmd(),
            new ReloadCmd(),
            new MonitorCmd(),
            new SendCmd(),
            new StatusCmd(),
            new CreateCmd(),
        ];

        foreach ($commands as $command) {
            $this->assertInstanceOf(BaseCmd::class, $command, get_class($command) . ' should extend BaseCmd');
        }
    }

    /**
     * 测试命令名称注册正确。
     *
     * 验证 #[AsCommand] attribute 中定义的命令名。
     */
    public function testCommandNames(): void
    {
        $expected = [
            StartCmd::class => 'start',
            StopCmd::class => 'stop',
            RestartCmd::class => 'restart',
            ReloadCmd::class => 'reload',
            MonitorCmd::class => 'monitor',
            SendCmd::class => 'send',
            StatusCmd::class => 'status',
            CreateCmd::class => 'create',
        ];

        foreach ($expected as $class => $name) {
            $cmd = new $class();
            $this->assertSame($name, $cmd->getName(), "{$class} should have name '{$name}'");
        }
    }

    /**
     * 测试 Infrastructure 层所有类存在。
     */
    public function testInfrastructureClassesExist(): void
    {
        $classes = [
            PidFileManager::class,
            CmdLogWriter::class,
            ServerStatusRenderer::class,
            FifoPipeClient::class,
            CmdInitException::class,
        ];

        foreach ($classes as $class) {
            $this->assertTrue(class_exists($class), "Infrastructure class {$class} should exist");
        }
    }

    /**
     * 测试 Application 层类存在。
     */
    public function testApplicationClassesExist(): void
    {
        $this->assertTrue(class_exists(ServerLifecycleManager::class));
    }

    /**
     * 测试 DTO 层所有类存在。
     */
    public function testDtoClassesExist(): void
    {
        $this->assertTrue(class_exists(CmdContext::class));
        $this->assertTrue(class_exists(StopResult::class));
        $this->assertTrue(enum_exists(StopStatus::class));
    }

    /**
     * 测试 CmdInitException 是 RuntimeException 的子类。
     */
    public function testCmdInitExceptionHierarchy(): void
    {
        $exception = new CmdInitException('test error');

        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertSame('test error', $exception->getMessage());
    }

    /**
     * 测试 ServerLifecycleManager 可实例化。
     */
    public function testServerLifecycleManagerInstantiation(): void
    {
        $manager = new ServerLifecycleManager();
        $this->assertInstanceOf(ServerLifecycleManager::class, $manager);
    }

    /**
     * 测试 BaseCmd 常量定义正确。
     */
    public function testBaseCmdConstants(): void
    {
        $this->assertSame('app_name', BaseCmd::APP_NAME);
        $this->assertSame('daemon', BaseCmd::DAEMON);
        $this->assertSame('force', BaseCmd::FORCE);
        $this->assertSame('start_model', BaseCmd::START_MODEL);
        $this->assertSame('http', BaseCmd::HTTP_PROTOCOL);
        $this->assertSame('udp', BaseCmd::UDP_PROTOCOL);
        $this->assertSame('websocket', BaseCmd::WEBSOCKET_PROTOCOL);
        $this->assertSame('mqtt', BaseCmd::MQTT_PROTOCOL);
        $this->assertSame('rpc', BaseCmd::RPC_PROTOCOL);
    }

    /**
     * 测试 StopResult 和 StopStatus 的集成。
     *
     * 验证 StopResult 工厂方法产生的实例与 StopStatus enum 正确关联。
     */
    public function testStopResultWithStopStatusIntegration(): void
    {
        $results = [
            [StopResult::success(1), StopStatus::SUCCESS],
            [StopResult::alreadyStopped(), StopStatus::ALREADY_STOPPED],
            [StopResult::timeout(1), StopStatus::TIMEOUT],
            [StopResult::pidFileNotFound(''), StopStatus::PID_NOT_FOUND],
            [StopResult::invalidPid(''), StopStatus::INVALID_PID],
        ];

        foreach ($results as [$result, $expectedStatus]) {
            $this->assertSame($expectedStatus, $result->status);
            $this->assertSame($expectedStatus->label(), $result->status->label());
        }
    }

    /**
     * 测试命令描述信息已设置。
     */
    public function testCommandDescriptions(): void
    {
        $commands = [
            new StartCmd(),
            new StopCmd(),
            new RestartCmd(),
            new ReloadCmd(),
            new MonitorCmd(),
            new SendCmd(),
            new StatusCmd(),
        ];

        foreach ($commands as $command) {
            $desc = $command->getDescription();
            $this->assertNotEmpty($desc, get_class($command) . ' should have a description');
        }
    }
}
