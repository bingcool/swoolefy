<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Worker\Cron;

use PHPUintTest\TestCase;
use Swoolefy\Exception\CronException;
use Swoolefy\Worker\Cron\CronExpressionSchedule;
use Swoolefy\Worker\Cron\ExpressionParser;
use Swoolefy\Worker\Cron\IntervalSchedule;

/**
 * ExpressionParser / IntervalSchedule / CronExpressionSchedule（文档 3/4/40.1）。
 *
 * 秒级 Interval 对齐 unix 网格，禁止 finish_time + N 漂移。
 * Linux Cron 取严格晚于 from 的下一合法点。非法 / 0 间隔抛 CronException。
 *
 * @see \Swoolefy\Worker\Cron\ExpressionParser
 */
final class ExpressionParserTest extends TestCase
{
    private ExpressionParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new ExpressionParser();
    }

    /**
     * 纯数字 expression → IntervalSchedule，nextRunAt 对齐网格。
     *
     * @dataProvider intervalExpressions
     */
    public function testSecondIntervalNextRunAt(int|string $expression, int $from, int $expected): void
    {
        $schedule = $this->parser->parse($expression);
        $this->assertInstanceOf(IntervalSchedule::class, $schedule);
        $this->assertSame($expected, $schedule->calculateNextRunAt($from));
    }

    /**
     * @return list<array{0:int|string,1:int,2:int}>
     */
    public static function intervalExpressions(): array
    {
        // 对齐 unix 网格：from=1000 → 15 的下一格 1005
        return [
            [15, 1000, 1005],
            ['20', 1000, 1020],
            [25, 1000, 1025],
            [15, 1005, 1020],
            [15, 0, 15],
        ];
    }

    /**
     * 连续用“上一计划点”计算，必须落在 15/30/45/60，而不是 finish+15。
     */
    public function testIntervalDoesNotDriftFromFinishTime(): void
    {
        $schedule = $this->parser->parse(15);
        $planned = 0;
        $points = [];
        for ($i = 0; $i < 4; ++$i) {
            $planned = $schedule->calculateNextRunAt($planned);
            $points[] = $planned;
        }
        $this->assertSame([15, 30, 45, 60], $points);
    }

    /**
     * 五段 Linux Cron 在 UTC 下取严格下一触发点。
     *
     * @dataProvider linuxCronExpressions
     */
    public function testLinuxCronNextRunAt(string $expression, string $from, string $expected): void
    {
        $schedule = $this->parser->parse($expression, 'UTC');
        $this->assertInstanceOf(CronExpressionSchedule::class, $schedule);
        $fromTs = (new \DateTimeImmutable($from, new \DateTimeZone('UTC')))->getTimestamp();
        $expectedTs = (new \DateTimeImmutable($expected, new \DateTimeZone('UTC')))->getTimestamp();
        $this->assertSame($expectedTs, $schedule->calculateNextRunAt($fromTs));
    }

    /**
     * @return list<array{0:string,1:string,2:string}>
     */
    public static function linuxCronExpressions(): array
    {
        return [
            ['*/5 * * * *', '2026-08-15 10:00:00', '2026-08-15 10:05:00'],
            ['0 * * * *', '2026-08-15 10:00:00', '2026-08-15 11:00:00'],
            ['30 2 * * *', '2026-08-15 01:00:00', '2026-08-15 02:30:00'],
        ];
    }

    /**
     * 非法 Cron 字符串必须抛 CronException，供 applyOp 隔离跳过。
     */
    public function testInvalidExpressionThrows(): void
    {
        $this->expectException(CronException::class);
        $this->parser->parse('not-a-cron');
    }

    /**
     * 间隔 0 非法，不能武装“立即死循环”的 Timer。
     */
    public function testZeroIntervalRejected(): void
    {
        $this->expectException(CronException::class);
        $this->parser->parse(0);
    }
}
