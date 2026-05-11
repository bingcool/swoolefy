<?php

declare(strict_types=1);


namespace Test\Module\Cron\Response\CronTaskManager;

use Test\Module\Cron\Dto\CronTaskManager\CronTaskRowDto;
use Swoolefy\Http\BaseResponse;

class CronTaskRowResponse extends BaseResponse
{
    protected CronTaskRowDto $row;

    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(array $attributes)
    {
        $this->row = CronTaskRowDto::fromEntityRow($attributes);
    }

    public function getRow(): CronTaskRowDto
    {
        return $this->row;
    }

    public function setRow(CronTaskRowDto $row): static
    {
        $this->row = $row;

        return $this;
    }

    public function getData(): array
    {
        return $this->row->toDeepArray();
    }
}
