<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Core;

use Cron\CronExpression;
use PHPUintTest\TestCase;
use ReflectionProperty;
use Swoolefy\Core\Crontab\AbstractCronController;

/**
 * AbstractCronController::runCron() 每 2 秒被调用一次：同一触发分钟只执行一次；进程启动所在的分钟不执行。
 */
final class CronSlotTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetStatics();
        putenv('run-once-cron=0');
    }

    protected function tearDown(): void
    {
        $this->resetStatics();
        parent::tearDown();
    }

    public function testBootMinuteIsSkippedAndSlotRunsOnce(): void
    {
        $runs = 0;
        $func = static function () use (&$runs): void {
            ++$runs;
        };

        $this->controller()->runCron('every-minute', '* * * * *', $func);
        $this->assertSame(0, $runs, 'boot minute must be skipped');

        $this->setBootMinute(time() - 120);
        $this->controller()->runCron('every-minute', '* * * * *', $func);
        $this->controller()->runCron('every-minute', '* * * * *', $func);
        $this->assertSame(1, $runs, 'same slot runs once');
    }

    public function testNotDueDoesNotRun(): void
    {
        $this->setBootMinute(time() - 120);
        $runs = 0;
        $minute = ((int) date('i') + 30) % 60;
        $this->controller()->runCron('not-due', $minute . ' * * * *', static function () use (&$runs): void {
            ++$runs;
        });
        $this->assertSame(0, $runs);
    }

    private function controller(): AbstractCronController
    {
        return new class extends AbstractCronController {
            public function __construct()
            {
            }

            public function doCronTask(CronExpression|float $cron, string $cronName)
            {
            }

            public function isDefer()
            {
                return true;
            }
        };
    }

    private function setBootMinute(int $time): void
    {
        $prop = new ReflectionProperty(AbstractCronController::class, 'schedulerBoot');
        $prop->setValue(null, ['pid' => getmypid(), 'minute' => $time - ($time % 60)]);
    }

    private function resetStatics(): void
    {
        (new ReflectionProperty(AbstractCronController::class, 'schedulerBoot'))->setValue(null, null);
        (new ReflectionProperty(AbstractCronController::class, 'cronLastRunSlot'))->setValue(null, []);
    }
}
