<?php

declare(strict_types=1);

namespace Test\Module\Cron\Dto\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\AbstractDto;

/**
 * 执行趋势查询 DTO。
 */
class ExecutionTrendQueryDto extends AbstractDto
{
    #[ApiProperty(description: '24h / 7d / 30d')]
    protected string $range = '24h';

    public static function of(string $range): self
    {
        $dto = new self();
        $dto->setRange($range);

        return $dto;
    }

    public function getRange(): string
    {
        return $this->range;
    }

    public function setRange(string $range): static
    {
        $range = strtolower(trim($range));
        $this->range = in_array($range, ['24h', '7d', '30d'], true) ? $range : '24h';

        return $this;
    }
}
