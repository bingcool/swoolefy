<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Module\Cron;

use PHPUintTest\TestCase;
use Test\Module\Cron\Dto\CronTaskManager\CronTimeRangeDto;
use Test\Module\Cron\Request\CronTaskManager\CronTaskCreateRequest;
use Test\Module\Cron\Request\CronTaskManager\CronTaskUpdateRequest;

final class CronTaskTimeRangeRequestNormalizationTest extends TestCase
{
    public function testUpdateRequestAcceptsObjectArrayTimeRanges(): void
    {
        $request = (new CronTaskUpdateRequest())
            ->setId(1)
            ->setCronBetween([])
            ->setCronSkip([
                ['start' => '16:49', 'end' => '16:50'],
            ]);

        $payload = $request->toPayloadArray();

        $this->assertSame([
            ['start' => '16:49', 'end' => '16:50'],
        ], $payload['cron_skip'] ?? null);
        $this->assertNull($payload['cron_between'] ?? null);
    }

    public function testCreateRequestKeepsLegacyDtoInput(): void
    {
        $range = (new CronTimeRangeDto())
            ->setStart('08:00')
            ->setEnd('12:00');

        $request = (new CronTaskCreateRequest())
            ->setName('demo')
            ->setExpression('15')
            ->setCommand('/bin/echo')
            ->setExecType(1)
            ->setNodeId(1)
            ->setCronBetween([$range])
            ->setCronSkip([
                ['start' => '16:49', 'end' => '16:50'],
            ]);

        $payload = $request->toPayloadArray();

        $this->assertSame([
            ['start' => '08:00', 'end' => '12:00'],
        ], $payload['cron_between'] ?? null);
        $this->assertSame([
            ['start' => '16:49', 'end' => '16:50'],
        ], $payload['cron_skip'] ?? null);
    }

    public function testUpdateRequestAcceptsJsonStringRanges(): void
    {
        $request = (new CronTaskUpdateRequest())
            ->setId(2)
            ->setCronSkip('[{"start":"06:00","end":"07:00"}]');

        $payload = $request->toPayloadArray();
        $this->assertSame([
            ['start' => '06:00', 'end' => '07:00'],
        ], $payload['cron_skip'] ?? null);
    }
}
