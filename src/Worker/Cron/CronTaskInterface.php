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

    /**
     * @param ScheduleEvent|CronUrlTaskMetaDto $scheduleTask
     * @param string $execBatchId
     * @param string $message
     * @return mixed
     */
    public function logCronTaskRuntime(
        ScheduleEvent|CronUrlTaskMetaDto $scheduleTask,
        string $execBatchId,
        string $message,
        int $pid = 0
    );
}