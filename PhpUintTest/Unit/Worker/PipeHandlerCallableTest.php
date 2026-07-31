<?php

declare(strict_types=1);

namespace PhpUintTest\Unit\Worker;

use PHPUnit\Framework\TestCase;
use Swoolefy\Worker\AbstractBaseWorker;

/**
 * Pipe handler 使用 is_callable（审计 43 / 阶段四 6.5）。
 * 覆盖：公有/受保护内部 handler 可调；私有、缺失方法与非法外部 handler 拒绝并返回可识别失败。
 */
final class PipeHandlerCallableTest extends TestCase
{
    protected function setUp(): void
    {
        ExternalPipeHandlerStub::$calls = [];
    }

    /**
     * 测已注册的公有内部 handler 可调用并返回 ok。
     */
    public function testPublicInternalHandlerIsInvoked(): void
    {
        $worker = new PipeHandlerWorkerStub();
        $worker->setCustomHandles([
            'do-public' => [PipeHandlerWorkerStub::class, 'publicPipeHandler'],
        ]);

        $result = $worker->onPipeMsg(
            json_encode(['action' => 'do-public', 'request_id' => 'r1'], JSON_UNESCAPED_UNICODE),
            'master',
            0,
            false
        );

        $this->assertSame(['ok' => true, 'command' => 'do-public', 'request_id' => 'r1'], $result);
        $this->assertSame(['publicPipeHandler'], $worker->calls);
    }

    /**
     * 测系统受保护 handler（run-once-cron）可通过 is_callable 调用。
     */
    public function testProtectedSystemHandlerIsInvoked(): void
    {
        $worker = new PipeHandlerWorkerStub();
        $before = getenv('run-once-cron');
        $result = $worker->onPipeMsg(
            json_encode(['action' => 'run-once-cron', 'request_id' => 'r-cron'], JSON_UNESCAPED_UNICODE),
            'master',
            0,
            false
        );

        $this->assertTrue($result['ok'] ?? false);
        $this->assertSame('1', getenv('run-once-cron'));
        if ($before === false) {
            putenv('run-once-cron');
        } else {
            putenv('run-once-cron=' . $before);
        }
    }

    /**
     * 测私有内部方法不可调用（父类作用域下 is_callable 为 false）。
     */
    public function testPrivateInternalHandlerIsRejected(): void
    {
        $worker = new PipeHandlerWorkerStub();
        $worker->setCustomHandles([
            'do-private' => [PipeHandlerWorkerStub::class, 'privatePipeHandler'],
        ]);

        $result = $worker->onPipeMsg(
            json_encode(['action' => 'do-private', 'request_id' => 'r2'], JSON_UNESCAPED_UNICODE),
            'master',
            0,
            false
        );

        $this->assertSame('invalid_pipe_handler', $result['error'] ?? null);
        $this->assertFalse($result['ok'] ?? true);
        $this->assertSame([], $worker->calls);
        $this->assertNotEmpty($worker->errorLogs);
        $this->assertStringContainsString('handler_type=internal', $worker->errorLogs[0]);
        $this->assertStringContainsString('request_id=r2', $worker->errorLogs[0]);
    }

    /**
     * 测缺失内部方法拒绝调用。
     */
    public function testMissingInternalMethodIsRejected(): void
    {
        $worker = new PipeHandlerWorkerStub();
        $worker->setCustomHandles([
            'do-missing' => [PipeHandlerWorkerStub::class, 'notExistsMethod'],
        ]);

        $result = $worker->onPipeMsg(
            json_encode(['action' => 'do-missing', 'request_id' => 'r3'], JSON_UNESCAPED_UNICODE),
            'master',
            0,
            false
        );

        $this->assertSame('invalid_pipe_handler', $result['error'] ?? null);
        $this->assertSame([], $worker->calls);
    }

    /**
     * 测合法外部 handler（公有实例方法）可调用。
     */
    public function testExternalPublicHandlerIsInvoked(): void
    {
        ExternalPipeHandlerStub::$calls = [];
        $worker = new PipeHandlerWorkerStub();
        $worker->setCustomHandles([
            'do-ext' => [ExternalPipeHandlerStub::class, 'handle'],
        ]);

        $result = $worker->onPipeMsg(
            json_encode(['action' => 'do-ext', 'request_id' => 'r4'], JSON_UNESCAPED_UNICODE),
            'master',
            0,
            false
        );

        $this->assertTrue($result['ok'] ?? false);
        $this->assertSame(['handle'], ExternalPipeHandlerStub::$calls);
    }

    /**
     * 测外部静态方法可调用。
     */
    public function testExternalStaticHandlerIsInvoked(): void
    {
        ExternalPipeHandlerStub::$calls = [];
        $worker = new PipeHandlerWorkerStub();
        $worker->setCustomHandles([
            'do-static' => [ExternalPipeHandlerStub::class, 'staticHandle'],
        ]);

        $result = $worker->onPipeMsg(
            json_encode(['action' => 'do-static', 'request_id' => 'r5'], JSON_UNESCAPED_UNICODE),
            'master',
            0,
            false
        );

        $this->assertTrue($result['ok'] ?? false);
        $this->assertSame(['staticHandle'], ExternalPipeHandlerStub::$calls);
    }

    /**
     * 测非法外部 handler（类不存在）拒绝并记录 command/handler_type/request_id。
     */
    public function testInvalidExternalClassIsRejected(): void
    {
        $worker = new PipeHandlerWorkerStub();
        $worker->setCustomHandles([
            'do-bad' => ['PhpUintTest\\Unit\\Worker\\NotExistsPipeHandler', 'handle'],
        ]);

        $result = $worker->onPipeMsg(
            json_encode(['action' => 'do-bad', 'request_id' => 'r6'], JSON_UNESCAPED_UNICODE),
            'master',
            0,
            false
        );

        $this->assertSame('invalid_pipe_handler', $result['error'] ?? null);
        $this->assertStringContainsString('handler_type=external', $worker->errorLogs[0] ?? '');
        $this->assertStringContainsString('command=do-bad', $worker->errorLogs[0] ?? '');
        $this->assertStringContainsString('request_id=r6', $worker->errorLogs[0] ?? '');
    }

    /**
     * 测外部类存在但方法不可调用时拒绝。
     */
    public function testExternalMissingMethodIsRejected(): void
    {
        $worker = new PipeHandlerWorkerStub();
        $worker->setCustomHandles([
            'do-ext-miss' => [ExternalPipeHandlerStub::class, 'missingMethod'],
        ]);

        $result = $worker->onPipeMsg(
            json_encode(['action' => 'do-ext-miss', 'request_id' => 'r7'], JSON_UNESCAPED_UNICODE),
            'master',
            0,
            false
        );

        $this->assertSame('invalid_pipe_handler', $result['error'] ?? null);
        $this->assertSame([], ExternalPipeHandlerStub::$calls);
    }
}

/**
 * Pipe 测试用 Worker 替身。
 */
final class PipeHandlerWorkerStub extends AbstractBaseWorker
{
    /** @var list<string> */
    public array $calls = [];

    /** @var list<string> */
    public array $errorLogs = [];

    public function __construct()
    {
        parent::__construct('pipe-handler-stub', false, [], null, false);
    }

    public function run()
    {
    }

    /**
     * @param array<string, array{0: class-string, 1: string}> $handles
     */
    public function setCustomHandles(array $handles): void
    {
        $this->customCommandHandle = $handles;
    }

    public function publicPipeHandler($msg, string $fromProcessName, int $fromProcessWorkerId, bool $is_proxy_by_master): void
    {
        $this->calls[] = 'publicPipeHandler';
    }

    private function privatePipeHandler($msg, string $fromProcessName, int $fromProcessWorkerId, bool $is_proxy_by_master): void
    {
        $this->calls[] = 'privatePipeHandler';
    }

    protected function fmtWriteError($msg)
    {
        $this->errorLogs[] = (string) $msg;
    }
}

/**
 * 外部 Pipe handler 替身。
 */
final class ExternalPipeHandlerStub
{
    /** @var list<string> */
    public static array $calls = [];

    public function handle($msg, string $fromProcessName, int $fromProcessWorkerId, bool $is_proxy_by_master): void
    {
        self::$calls[] = 'handle';
    }

    public static function staticHandle($msg, string $fromProcessName, int $fromProcessWorkerId, bool $is_proxy_by_master): void
    {
        self::$calls[] = 'staticHandle';
    }
}
