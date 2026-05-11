<?php

declare(strict_types=1);


namespace Test\Module\Cron\Response\CronTaskManager;

use Test\Module\Cron\Response\CronTaskManager\ListTasksPageResult;
use Swoolefy\Http\BasePageResultResponse;

class ListTasksResponse extends BasePageResultResponse
{
    protected ListTasksPageResult $data;

    public function __construct(ListTasksPageResult $data)
    {
        $this->data = $data;
    }
}
