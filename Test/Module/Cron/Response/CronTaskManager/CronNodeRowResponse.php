<?php

declare(strict_types=1);


namespace Test\Module\Cron\Response\CronTaskManager;

use Test\Module\Cron\Dto\CronTaskManager\CronAgentNodeRowDto;
use Swoolefy\Http\BaseResponse;

class CronNodeRowResponse extends BaseResponse
{
    protected CronAgentNodeRowDto $row;

    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(array $attributes)
    {
        $this->row = CronAgentNodeRowDto::fromEntityRow($attributes);
    }

    public function getRow(): CronAgentNodeRowDto
    {
        return $this->row;
    }

    public function setRow(CronAgentNodeRowDto $row): static
    {
        $this->row = $row;

        return $this;
    }

    public function getData(): array
    {
        return $this->row->toDeepArray();
    }
}
