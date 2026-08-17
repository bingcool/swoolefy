<?php

declare(strict_types=1);

namespace Test\Module\Cron\Dto\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\AbstractDto;

/**
 * 批量启停入参。
 */
class BatchStatusDto extends AbstractDto
{
    /**
     * @var list<int>
     */
    #[ApiProperty(description: '任务 ID 列表')]
    protected array $ids = [];

    #[ApiProperty(description: '目标状态：0/1')]
    protected int $status = 0;

    /**
     * @param list<int> $ids
     */
    public static function of(array $ids, int $status): self
    {
        $dto = new self();
        $dto->ids = $ids;
        $dto->status = $status;

        return $dto;
    }

    /**
     * @return list<int>
     */
    public function getIds(): array
    {
        return $this->ids;
    }

    public function getStatus(): int
    {
        return $this->status;
    }
}
