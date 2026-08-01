<?php

declare(strict_types=1);

namespace PHPUintTest\Coroutine\Core;

use PHPUintTest\CoroutineTestCase;
use Swoole\Coroutine\Channel;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Coroutine\Context;
use Swoolefy\Core\Timer\Tick;

/**
 * 阶段二 4.8（审计项 26）：Tick 注册不捕获请求 Context。
 * 目标：在用户 A 请求内注册 Tick，后续触发不含用户 A / tenant A；
 * 触发协程仅有系统字段（含非空 x-trace-id）。
 */
final class TickContextIsolationTest extends CoroutineTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        BaseServer::setAppConf(['components' => []]);
    }

    /**
     * 测请求内注册的 Tick 触发时不含 user/tenant，仅有系统字段与 x-trace-id。
     * 对应问题：长期 Timer 隐式复制请求身份造成跨请求串用。
     */
    public function testTickTriggerDoesNotCarryRequestUserIdentity(): void
    {
        $this->runInCoroutine(function (): void {
            Context::set('user_id', 'user-A');
            Context::set('tenant', 'tenant-A');

            $ch = new Channel(1);
            $timerId = Tick::tickTimer(50, static function () use ($ch): void {
                $ch->push([
                    'user_id' => Context::get('user_id'),
                    'tenant' => Context::get('tenant'),
                    'sys_time' => Context::get(Tick::CTX_SYS_TICK_TRIGGER_TIME),
                    // 触发时新生成的 trace id，便于日志排查
                    'trace_id' => Context::get(Tick::CTX_X_TRACE_ID),
                ]);
            });

            try {
                $payload = $ch->pop(2);
                $this->assertIsArray($payload);
                $this->assertNull($payload['user_id']);
                $this->assertNull($payload['tenant']);
                $this->assertNotNull($payload['sys_time']);
                $this->assertIsString($payload['trace_id']);
                $this->assertNotSame('', $payload['trace_id']);
                // tick 的 x-trace-id 须以 ticktimer_ 开头
                $this->assertStringStartsWith(Tick::TRACE_ID_PREFIX_TICKTIMER, $payload['trace_id']);
            } finally {
                Tick::delTicker($timerId);
            }
        });
    }

    /**
     * After 触发同样写入非空 x-trace-id（aftertimer_ 前缀），且不携带请求身份。
     */
    public function testAfterTriggerWritesTraceIdWithoutRequestIdentity(): void
    {
        $this->runInCoroutine(function (): void {
            Context::set('user_id', 'user-A');
            Context::set('tenant', 'tenant-A');

            $ch = new Channel(1);
            Tick::afterTimer(50, static function () use ($ch): void {
                $ch->push([
                    'user_id' => Context::get('user_id'),
                    'tenant' => Context::get('tenant'),
                    'sys_time' => Context::get(Tick::CTX_SYS_TICK_TRIGGER_TIME),
                    'trace_id' => Context::get(Tick::CTX_X_TRACE_ID),
                ]);
            });

            $payload = $ch->pop(2);
            $this->assertIsArray($payload);
            $this->assertNull($payload['user_id']);
            $this->assertNull($payload['tenant']);
            $this->assertNotNull($payload['sys_time']);
            $this->assertIsString($payload['trace_id']);
            $this->assertNotSame('', $payload['trace_id']);
            // after 的 x-trace-id 须以 aftertimer_ 开头
            $this->assertStringStartsWith(Tick::TRACE_ID_PREFIX_AFTERTIMER, $payload['trace_id']);
        });
    }
}
