<?php

declare(strict_types=1);


namespace Test\Module\Cron\Response\CronTaskManager;

use Test\Module\Cron\Dto\CronTaskManager\CronAgentNodeRowDto;
use Swoolefy\Http\BaseResponse;

class CronNodeRowResponse extends BaseResponse
{
    protected CronAgentNodeRowDto $data;

    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(array $attributes)
    {
        $this->data = CronAgentNodeRowDto::fromEntityRow($attributes);
    }

    public function getData(): CronAgentNodeRowDto
    {
        return $this->data;
    }
}
