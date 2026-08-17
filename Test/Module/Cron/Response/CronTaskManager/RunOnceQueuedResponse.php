<?php

declare(strict_types=1);

namespace Test\Module\Cron\Response\CronTaskManager;

use Swoolefy\Http\BaseResponse;
use Test\Module\Cron\Dto\CronTaskManager\RunOnceQueuedDto;

class RunOnceQueuedResponse extends BaseResponse
{
    protected RunOnceQueuedDto $result;

    public function __construct(RunOnceQueuedDto $result)
    {
        $this->result = $result;
    }

    public function getData(): array
    {
        return $this->result->toDeepArray();
    }
}
