<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Module\Cron;

use PHPUintTest\TestCase;
use Test\Module\Cron\Dto\CronTaskManager\NodeIdDto;
use Test\Module\Cron\Entity\CronAgentNodeEntity;
use Test\Module\Cron\Exception\CronTaskException;
use Test\Module\Cron\Service\CronTaskManagerService;

/**
 * 节点删除：软删 deleted_at；列表与 loadById 可见性一致。
 */
final class CronNodeDeleteTest extends TestCase
{
    public function testQueryNotDeletedUsesSoftDeleteField(): void
    {
        $this->assertSame('deleted_at', CronAgentNodeEntity::getSoftDeleteField());
        $this->assertTrue((new CronAgentNodeEntity())->isSoftDelete());
    }

    public function testDeleteRejectsEmptyId(): void
    {
        $this->expectException(CronTaskException::class);
        $this->expectExceptionMessage('id不能为空');
        (new CronTaskManagerService())->deleteNode(NodeIdDto::of(0));
    }
}
