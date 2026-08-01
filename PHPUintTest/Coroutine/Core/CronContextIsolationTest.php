<?php

declare(strict_types=1);

namespace PHPUintTest\Coroutine\Core;

use PHPUintTest\CoroutineTestCase;
use Swoole\Coroutine\Channel;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Coroutine\Context;
use Swoolefy\Core\Crontab\CrontabManager;
use Swoolefy\Core\Timer\Tick;

/**
 * 阶段二 4.8：Cron 与 Tick 同策略——注册不捕获请求 Context，
 * 触发时仅写系统字段（含非空 x-trace-id）。
 */
final class CronContextIsolationTest extends CoroutineTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        BaseServer::setAppConf(['components' => []]);
    }

    /**
     * 数值间隔 Cron：触发不含 user/tenant，且有独立 x-trace-id。
     */
    public function testCronTriggerWritesTraceIdWithoutRequestIdentity(): void
    {
        $this->runInCoroutine(function (): void {
            Context::set('user_id', 'user-A');
            Context::set('tenant', 'tenant-A');

            $ch = new Channel(1);
            $cronName = 'cron-trace-isolation-' . uniqid('', true);

            CrontabManager::getInstance()->addRule(
                $cronName,
                0.05,
                static function () use ($ch): void {
                    $ch->push([
                        'user_id' => Context::get('user_id'),
                        'tenant' => Context::get('tenant'),
                        'sys_name' => Context::get(Tick::CTX_SYS_CRON_NAME),
                        'sys_time' => Context::get(Tick::CTX_SYS_CRON_TRIGGER_TIME),
                        // 触发时新生成的 trace id，便于日志排查
                        'trace_id' => Context::get(Tick::CTX_X_TRACE_ID),
                    ]);
                }
            );

            try {
                $payload = $ch->pop(2);
                $this->assertIsArray($payload);
                $this->assertNull($payload['user_id']);
                $this->assertNull($payload['tenant']);
                $this->assertSame($cronName, $payload['sys_name']);
                $this->assertNotNull($payload['sys_time']);
                $this->assertIsString($payload['trace_id']);
                $this->assertNotSame('', $payload['trace_id']);
            } finally {
                CrontabManager::getInstance()->removeCronTaskByName($cronName);
            }
        });
    }
}
