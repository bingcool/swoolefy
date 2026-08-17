<?php

declare(strict_types=1);

namespace Test\Module\Cron\Dto\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\AbstractDto;

/**
 * 批量启停结果。
 */
class BatchStatusResultDto extends AbstractDto
{
    /**
     * @var list<int>
     */
    #[ApiProperty(description: '已更新的任务 ID')]
    protected array $updatedIds = [];

    #[ApiProperty(description: '目标状态')]
    protected int $status = 0;

    /**
     * @param list<int> $updatedIds
     */
    public static function of(array $updatedIds, int $status): self
    {
        $dto = new self();
        $dto->updatedIds = $updatedIds;
        $dto->status = $status;

        return $dto;
    }
}
