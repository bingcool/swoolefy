<?php
namespace Swoolefy\Worker\Cron;

use Swoolefy\Core\Schedule\ScheduleEvent;
use Swoolefy\Worker\Dto\CronUrlTaskMetaDto;

interface CronTaskInterface
{
    /**
     * @return void
     */
    public function fetchCronTask(int $execType);

    public function logCronTaskRuntime(ScheduleEvent|CronUrlTaskMetaDto $scheduleTask, string $logId, string $message);
}