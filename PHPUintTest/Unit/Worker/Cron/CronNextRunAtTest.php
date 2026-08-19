<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Worker\Cron;

use PHPUintTest\TestCase;
use Swoolefy\Worker\Cron\CronNextRunAt;
use Swoolefy\Worker\Cron\ExpressionParser;

/**
 * HTTP Admin 列表推算 nextRunAt：与引擎同一套 ExpressionParser / 时间窗，不读 Worker 内存。
 */
final class CronNextRunAtTest extends TestCase
{
    /**
     * 启用的秒级 Interval：只给严格晚于基准的下一网格点，不回填历史。
     */
    public function testEnabledIntervalNextPoint(): void
    {
        $this->assertSame(1005, CronNextRunAt::compute($this->row(['expression' => '15']), 1000));
        $this->assertSame(1050, CronNextRunAt::compute($this->row(['expression' => '15']), 1040));
        $this->assertSame(
            (new ExpressionParser())->parse('20')->calculateNextRunAt(1000),
            CronNextRunAt::compute($this->row(['expression' => '20']), 1000),
        );
    }

    /**
     * 启用的 Linux Cron：UTC 下取严格下一触发点。
     */
    public function testEnabledCronExpressionNextPoint(): void
    {
        $from = (new \DateTimeImmutable('2026-08-15 10:00:00', new \DateTimeZone('UTC')))->getTimestamp();
        $expected = (new \DateTimeImmutable('2026-08-15 10:05:00', new \DateTimeZone('UTC')))->getTimestamp();
        $this->assertSame($expected, CronNextRunAt::compute($this->row([
            'expression' => '*/5 * * * *',
            'timezone' => 'UTC',
        ]), $from));
    }

    /**
     * 禁用任务视为暂停，nextRunAt 为 null。
     */
    public function testDisabledReturnsNull(): void
    {
        $this->assertNull(CronNextRunAt::compute($this->row(['status' => 0]), 1000));
        $this->assertNull(CronNextRunAt::compute($this->row([
            'expression' => '*/5 * * * *',
            'status' => 0,
        ]), 1000));
    }

    /**
     * 非法表达式不抛异常，返回 null，避免拖垮整页列表。
     */
    public function testInvalidExpressionDoesNotThrow(): void
    {
        $this->assertNull(CronNextRunAt::compute($this->row(['expression' => '???']), 1000));
        $this->assertNull(CronNextRunAt::compute($this->row(['expression' => '']), 1000));
        $this->assertNull(CronNextRunAt::compute($this->row(['expression' => 'not-a-cron']), 1000));
    }

    /**
     * cron_skip 命中时跳过该点，取下一个允许触发的表达式点。
     */
    public function testCronSkipAdvancesToNextAllowedPoint(): void
    {
        $tzWas = date_default_timezone_get();
        date_default_timezone_set('UTC');
        try {
            $from = (new \DateTimeImmutable('2026-08-15 10:00:00', new \DateTimeZone('UTC')))->getTimestamp();
            $plain = CronNextRunAt::compute($this->row([
                'expression' => '*/5 * * * *',
                'timezone' => 'UTC',
            ]), $from);
            $skipped = CronNextRunAt::compute($this->row([
                'expression' => '*/5 * * * *',
                'timezone' => 'UTC',
                'cron_skip' => [['start' => '10:00:00', 'end' => '10:06:00']],
            ]), $from);
            $expectedSkip = (new \DateTimeImmutable('2026-08-15 10:10:00', new \DateTimeZone('UTC')))->getTimestamp();
            $this->assertSame(
                (new \DateTimeImmutable('2026-08-15 10:05:00', new \DateTimeZone('UTC')))->getTimestamp(),
                $plain,
            );
            $this->assertSame($expectedSkip, $skipped);
        } finally {
            date_default_timezone_set($tzWas);
        }
    }

    public function testFormatDatetimeMatchesAdminTimestampStyle(): void
    {
        $this->assertSame('', CronNextRunAt::formatDatetime(null));
        $this->assertSame('', CronNextRunAt::formatDatetime(0));
        $ts = strtotime('2026-08-15 10:05:00');
        $this->assertSame('2026-08-15 10:05:00', CronNextRunAt::formatDatetime($ts));
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function row(array $override): array
    {
        return array_merge([
            'id' => 1,
            'cron_name' => 't',
            'expression' => '15',
            'command' => 'x',
            'status' => 1,
        ], $override);
    }
}
