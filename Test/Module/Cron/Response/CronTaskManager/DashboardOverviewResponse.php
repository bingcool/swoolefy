<?php

declare(strict_types=1);

namespace Test\Module\Cron\Response\CronTaskManager;

use Swoolefy\Http\BaseResponse;
use Test\Module\Cron\Dto\CronTaskManager\DashboardOverviewDto;

class DashboardOverviewResponse extends BaseResponse
{
    protected DashboardOverviewDto $overview;

    public function __construct(DashboardOverviewDto $overview)
    {
        $this->overview = $overview;
    }

    public function getData(): array
    {
        return $this->overview->toDeepArray();
    }
}
