<?php

declare(strict_types=1);

namespace PhpUintTest\Unit\Cmd;

use PHPUnit\Framework\TestCase;
use Swoolefy\Cmd\CreateCmd;
use Swoolefy\Exception\IOException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * CreateCmd 退出码与文件 IO（审计 19、38 / 阶段四 6.3、6.4）。
 * 覆盖：目录已存在/协议缺失返回失败码、stub 缺失抛 IOException、目标冲突不留半成品、正常创建 SUCCESS。
 */
final class CreateCmdExitAndIoTest extends TestCase
{
    private string $tmpRoot;

    private string $startDir;

    public static function setUpBeforeClass(): void
    {
        $projectRoot = dirname(__DIR__, 3);
        if (!defined('SRC_DIR_ROOT')) {
            define('SRC_DIR_ROOT', $projectRoot . '/src');
        }
        if (!defined('ROOT_PATH')) {
            define('ROOT_PATH', sys_get_temp_dir() . '/swoolefy_create_cmd_root');
        }
        if (!defined('START_DIR_ROOT')) {
            define('START_DIR_ROOT', ROOT_PATH);
        }
        if (!defined('APP_NAME')) {
            define('APP_NAME', 'CreateCmdDemoApp');
        }
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
        putenv('SWOOLEFY_CLI_ENV=dev');
    }

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/swoolefy_create_cmd_' . getmypid() . '_' . bin2hex(random_bytes(3));
        $this->startDir = $this->tmpRoot . '/start';
        mkdir($this->startDir, 0777, true);

        // 清理可能残留的 APP_PATH（常量指向固定路径）
        $this->removeTree(APP_PATH);
        $this->removeTree(dirname(APP_PATH) . '/.swoolefy_create_' . getmypid());
        if (!is_dir(dirname(APP_PATH))) {
            mkdir(dirname(APP_PATH), 0777, true);
        }
    }

    protected function tearDown(): void
    {
        $this->removeTree(APP_PATH);
        $this->removeTree($this->tmpRoot);
        // 清理同目录 staging 残留
        foreach (glob(dirname(APP_PATH) . '/.swoolefy_create_*') ?: [] as $staging) {
            $this->removeTree($staging);
        }
    }

    /**
     * 测目标目录已存在时返回 FAILURE，且不调用 exit。
     */
    public function testExistingDirectoryReturnsFailureCode(): void
    {
        mkdir(APP_PATH, 0777, true);
        $cmd = $this->newCmd();
        $code = $cmd->runExecute();

        $this->assertSame(Command::FAILURE, $code);
        $this->assertDirectoryExists(APP_PATH);
    }

    /**
     * 测协议缺失时返回 FAILURE。
     */
    public function testMissingProtocolReturnsFailureCode(): void
    {
        $cmd = $this->newCmd();
        $cmd->protocolOverride = null;
        $code = $cmd->runExecute();

        $this->assertSame(Command::FAILURE, $code);
        $this->assertDirectoryDoesNotExist(APP_PATH);
        $this->assertNoStagingLeft();
    }

    /**
     * 测非法空协议返回 INVALID。
     */
    public function testEmptyProtocolReturnsInvalidCode(): void
    {
        $cmd = $this->newCmd();
        $cmd->protocolOverride = '';
        $code = $cmd->runExecute();

        $this->assertSame(Command::INVALID, $code);
        $this->assertDirectoryDoesNotExist(APP_PATH);
    }

    /**
     * 测 stub 缺失时 copyFile 抛带源/目标路径的 IOException。
     */
    public function testMissingStubThrowsIoExceptionWithPaths(): void
    {
        $cmd = $this->newCmd();
        $missing = $this->tmpRoot . '/missing.stub.php';
        $target = $this->tmpRoot . '/out.php';

        try {
            $cmd->runCopyFile($missing, $target);
            $this->fail('expected IOException');
        } catch (IOException $e) {
            $this->assertSame($missing, $e->getSourcePath());
            $this->assertSame($target, $e->getTargetPath());
            $this->assertStringContainsString('Source stub missing', $e->getMessage());
        }

        $this->assertFileDoesNotExist($target);
    }

    /**
     * 测父目录不可写时创建失败且不留下最终目标目录/半成品。
     */
    public function testUnwritableParentLeavesNoTargetHalfProduct(): void
    {
        $readonlyParent = $this->tmpRoot . '/readonly_parent';
        mkdir($readonlyParent, 0555, true);

        $cmd = $this->newCmd();
        $cmd->appPathOverride = $readonlyParent . '/NewApp';
        $cmd->startDirOverride = $this->startDir;
        $code = $cmd->runExecute();

        $this->assertSame(Command::FAILURE, $code);
        $this->assertDirectoryDoesNotExist($cmd->appPathOverride);
        // staging 应被清理
        foreach (glob($readonlyParent . '/.swoolefy_create_*') ?: [] as $staging) {
            $this->fail('staging left behind: ' . $staging);
        }

        chmod($readonlyParent, 0777);
    }

    /**
     * 测目标冲突（创建中途已存在）时不覆盖用户目录，staging 清理。
     */
    public function testTargetConflictDoesNotOverwriteExistingUserDir(): void
    {
        $marker = 'user-owned-marker';
        $cmd = new class ($marker) extends CreateCmdExitAndIoTestableCreateCmd {
            public function __construct(private string $marker)
            {
                parent::__construct();
            }

            protected function buildApplicationSkeleton(string $appPathDir, string $appName, string $protocol): void
            {
                // 模拟生成过程中目标已被占用
                if (!is_dir(APP_PATH)) {
                    mkdir(APP_PATH, 0777, true);
                }
                file_put_contents(APP_PATH . '/keep.txt', $this->marker);
                parent::buildApplicationSkeleton($appPathDir, $appName, $protocol);
            }
        };
        $cmd->startDirOverride = $this->startDir;

        $code = $cmd->runExecute();
        $this->assertSame(Command::FAILURE, $code);
        $this->assertDirectoryExists(APP_PATH);
        $this->assertSame($marker, file_get_contents(APP_PATH . '/keep.txt'));
        $this->assertNoStagingLeft();
    }

    /**
     * 测正常创建返回 SUCCESS，并生成关键骨架文件。
     */
    public function testSuccessfulCreateReturnsSuccessAndPublishesSkeleton(): void
    {
        $cmd = $this->newCmd();
        $cmd->startDirOverride = $this->startDir;
        $code = $cmd->runExecute();

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertDirectoryExists(APP_PATH);
        $this->assertFileExists(APP_PATH . '/.env');
        $this->assertFileExists(APP_PATH . '/Protocol/conf.php');
        $this->assertFileExists(APP_PATH . '/Event.php');
        $this->assertFileExists(APP_PATH . '/HttpServer.php');
        $this->assertNoStagingLeft();
    }

    private function newCmd(): CreateCmdExitAndIoTestableCreateCmd
    {
        $cmd = new CreateCmdExitAndIoTestableCreateCmd();
        $cmd->startDirOverride = $this->startDir;
        return $cmd;
    }

    private function assertNoStagingLeft(): void
    {
        $left = glob(dirname(APP_PATH) . '/.swoolefy_create_*') ?: [];
        $this->assertSame([], $left, 'staging directories must be cleaned');
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            if (is_file($dir)) {
                @unlink($dir);
            }
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->removeTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}

/**
 * 可注入路径/协议的 CreateCmd 测试替身。
 */
class CreateCmdExitAndIoTestableCreateCmd extends CreateCmd
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
            'app_name' => APP_NAME,
        ], $definition);
        $input->setInteractive(false);
        return $this->execute($input, new BufferedOutput());
    }

    public function runCopyFile(string $source, string $target): void
    {
        $this->copyFile($source, $target);
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
        return parent::resolveProtocol($appName);
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
}
