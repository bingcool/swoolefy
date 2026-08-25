<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Module\Cron;

use PHPUintTest\TestCase;
use Test\Module\Cron\Dto\CronTaskManager\TaskLogsQueryDto;

final class TaskLogsQueryDtoTest extends TestCase
{
    public function testExecTypeOnlyKeepsShellOrHttp(): void
    {
        $dto = new TaskLogsQueryDto();

        $dto->setExecType(1);
        $this->assertSame(1, $dto->getExecType());

        $dto->setExecType(2);
        $this->assertSame(2, $dto->getExecType());

        $dto->setExecType(0);
        $this->assertNull($dto->getExecType());

        $dto->setExecType(99);
        $this->assertNull($dto->getExecType());
    }

    public function testTaskNameTrimmedIntoQuery(): void
    {
        $dto = new TaskLogsQueryDto();

        $dto->setTaskName('  order-sync  ');
        $this->assertSame('order-sync', $dto->getTaskName());

        $dto->setTaskName('   ');
        $this->assertNull($dto->getTaskName());
    }

    public function testTriggerTypeOnlyKeepsSchedulerOrManual(): void
    {
        $dto = new TaskLogsQueryDto();

        $dto->setTriggerType(1);
        $this->assertSame(1, $dto->getTriggerType());

        $dto->setTriggerType(2);
        $this->assertSame(2, $dto->getTriggerType());

        $dto->setTriggerType(0);
        $this->assertNull($dto->getTriggerType());

        $dto->setTriggerType(3);
        $this->assertNull($dto->getTriggerType());
    }
}
