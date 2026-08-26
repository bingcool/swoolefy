<?php

declare(strict_types=1);

namespace Test\Module\Cron\Response\CronTaskManager;

use Test\Module\Cron\Dto\CronTaskManager\CronAgentNodeGroupRowDto;
use Swoolefy\Http\BaseResponse;

class CronNodeGroupRowResponse extends BaseResponse
{
    protected CronAgentNodeGroupRowDto $data;

    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(array $attributes)
    {
        $this->data = CronAgentNodeGroupRowDto::fromEntityRow($attributes);
    }

    public function getData(): CronAgentNodeGroupRowDto
    {
        return $this->data;
    }

    public function setData($data): static
    {
        $this->data = $data;

        return $this;
    }
}
