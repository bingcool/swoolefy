<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Cmd;

require_once __DIR__ . '/create_cmd_test_helpers.php';
require_once __DIR__ . '/CreateCmdTestFilesystemTrait.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swoolefy\Cmd\CreateCmd;
use Swoolefy\Core\EventHandler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * CreateCmd 脚手架 event_handler 接线）。
 * 覆盖：HTTP/WebSocket/MQTT/RPC 生成 conf 后加载配置，event_handler 为应用 Event::class 且可实例化。
 */
final class CreateCmdEventHandlerTest extends TestCase
{
    use CreateCmdTestFilesystemTrait;

    private string $tmpRoot;

    private string $startDir;

    /** @var string 本用例专用应用目录（经 appPathOverride，不依赖全局 APP_PATH） */
    private string $appPath;

    private string $appName;

    private const TEST_APP_NAME_PREFIX = 'CreateCmdEventHandlerApp';

    public static function setUpBeforeClass(): void
    {
        $projectRoot = dirname(__DIR__, 3);
        if (!defined('SRC_DIR_ROOT')) {
            define('SRC_DIR_ROOT', $projectRoot . '/src');
        }
        if (!defined('ROOT_PATH')) {
            define('ROOT_PATH', sys_get_temp_dir() . '/swoolefy_create_cmd_event_handler_root');
        }
        if (!defined('START_DIR_ROOT')) {
            define('START_DIR_ROOT', ROOT_PATH);
        }
        if (!defined('APP_NAME')) {
            define('APP_NAME', 'CreateCmdEventHandlerApp');
        }
        // APP_PATH 可能已被 SwoolefyTestBootstrap 设为仓库 Test/；本类始终经 appPathOverride 使用独立临时目录
        if (!defined('APP_PATH')) {
            define('APP_PATH', ROOT_PATH . '/' . APP_NAME);
        }
        if (!defined('APP_META_ARR')) {
            define('APP_META_ARR', [
                APP_NAME => [
                    'protocol' => 'http',
                    'worker_port' => 9501,
                ],
            ]);
        }
        if (!defined('WORKER_PID_FILE_ROOT')) {
            define('WORKER_PID_FILE_ROOT', sys_get_temp_dir() . '/swoolefy_test');
        }
        defined('SWOOLEFY_DEV') or define('SWOOLEFY_DEV', 'dev');
        defined('SWOOLEFY_TEST') or define('SWOOLEFY_TEST', 'test');
        defined('SWOOLEFY_GRA') or define('SWOOLEFY_GRA', 'gra');
        defined('SWOOLEFY_PRD') or define('SWOOLEFY_PRD', 'prd');
        defined('SWOOLEFY_ENV') or define('SWOOLEFY_ENV', 'dev');
        putenv('SWOOLEFY_CLI_ENV=dev');
    }

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/swoolefy_create_cmd_event_' . getmypid() . '_' . bin2hex(random_bytes(3));
        $this->startDir = $this->tmpRoot . '/start';
        $this->appName = self::TEST_APP_NAME_PREFIX . '_' . bin2hex(random_bytes(2));
        $this->appPath = $this->tmpRoot . '/' . $this->appName;
        mkdir($this->startDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->safeRemoveTree($this->tmpRoot);
    }

    /**
     * 测各协议脚手架生成后，加载 conf 得到的 event_handler 为应用 Event 且非框架默认 Handler。
     */
    #[DataProvider('protocolProvider')]
    public function testGeneratedConfUsesApplicationEventHandler(string $protocol): void
    {
        $cmd = $this->newCmd();
        $cmd->protocolOverride = $protocol;
        $code = $cmd->runExecute();

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertFileExists($this->appPath . '/Event.php');
        $this->assertFileExists($this->appPath . '/Protocol/conf.php');

        require_once $this->appPath . '/Event.php';

        $expectedEventClass = $this->appName . '\\Event';
        $confContent = file_get_contents($this->appPath . '/Protocol/conf.php');
        $this->assertIsString($confContent);
        $this->assertStringContainsString(
            "'event_handler'            => \\{$this->appName}\\Event::class,",
            $confContent
        );

        $this->assertTrue(class_exists($expectedEventClass));
        $this->assertTrue(is_subclass_of($expectedEventClass, EventHandler::class));

        $handler = new $expectedEventClass();
        $this->assertInstanceOf(EventHandler::class, $handler);
    }

    /**
     * 测 HTTP 协议额外写入 application_bootstrap，且与 event_handler 同属应用命名空间。
     */
    public function testHttpConfAlsoSetsApplicationBootstrap(): void
    {
        $cmd = $this->newCmd();
        $cmd->protocolOverride = 'http';
        $this->assertSame(Command::SUCCESS, $cmd->runExecute());

        require_once $this->appPath . '/Bootstrap.php';
        $confContent = file_get_contents($this->appPath . '/Protocol/conf.php');
        $this->assertIsString($confContent);
        $this->assertStringContainsString(
            "'application_bootstrap'    => \\{$this->appName}\\Bootstrap::class,",
            $confContent
        );
        $this->assertStringContainsString(
            "'event_handler'            => \\{$this->appName}\\Event::class,",
            $confContent
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function protocolProvider(): iterable
    {
        yield 'http' => ['http'];
        yield 'websocket' => ['websocket'];
        yield 'mqtt' => ['mqtt'];
        yield 'rpc' => ['rpc'];
    }

    private function newCmd(): CreateCmdEventHandlerTestableCreateCmd
    {
        $cmd = new CreateCmdEventHandlerTestableCreateCmd();
        $cmd->appNameOverride = $this->appName;
        $cmd->appPathOverride = $this->appPath;
        $cmd->startDirOverride = $this->startDir;
        return $cmd;
    }
}

/**
 * 9.3 测试替身：可注入协议；RPC 尚无 RpcServer.stub 时仅生成 Event/conf。
 */
class CreateCmdEventHandlerTestableCreateCmd extends CreateCmd
{
    public ?string $appPathOverride = null;

    public ?string $appNameOverride = null;

    /** @var string|null|false false 表示使用父类逻辑 */
    public string|null|false $protocolOverride = false;

    public ?string $startDirOverride = null;

    public function __construct()
    {
        parent::__construct();
        $this->createDelaySeconds = 0;
    }

    public function runExecute(): int
    {
        $definition = $this->getDefinition();
        $input = new ArrayInput([
            'app_name' => $this->appNameOverride ?? APP_NAME,
        ], $definition);
        $input->setInteractive(false);
        return $this->execute($input, new BufferedOutput());
    }

    protected function resolveAppName(): string
    {
        return $this->appNameOverride ?? parent::resolveAppName();
    }

    protected function resolveAppPath(): string
    {
        return $this->appPathOverride ?? parent::resolveAppPath();
    }

    protected function resolveProtocol(string $appName): ?string
    {
        if ($this->protocolOverride !== false) {
            return $this->protocolOverride;
        }
        $protocol = parent::resolveProtocol($appName);
        if ($protocol === null && $this->appNameOverride !== null) {
            return 'http';
        }
        return $protocol;
    }

    protected function ensureRootServiceEntryFiles(): void
    {
        $root = $this->startDirOverride ?? START_DIR_ROOT;
        $pairs = [
            $root . '/daemon.php' => SRC_DIR_ROOT . '/Stubs/daemon.stub.php',
            $root . '/cron.php' => SRC_DIR_ROOT . '/Stubs/cron.stub.php',
            $root . '/script.php' => SRC_DIR_ROOT . '/Stubs/script.stub.php',
        ];
        foreach ($pairs as $target => $source) {
            if (!file_exists($target)) {
                $this->copyFile($source, $target);
            }
        }
    }

    protected function copyServerFile($appName, $protocol, ?string $appPathDir = null): void
    {
        if ($protocol === 'rpc') {
            $this->commonHandleFile($appPathDir, $appName);
            return;
        }
        parent::copyServerFile($appName, $protocol, $appPathDir);
    }
}
