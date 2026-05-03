<?php
namespace Swoolefy\Worker\Cron;

use Swoolefy\Core\Schedule\ScheduleEvent;
use Swoolefy\Worker\Dto\CronUrlTaskMetaDtoWorker;

interface CronTaskInterface
{
    /**
     * @return void
     */
    public function fetchCronTask(int $execType, $nodeId);

    /**
     * @param ScheduleEvent|CronUrlTaskMetaDtoWorker $scheduleTask
     * @param string $execBatchId
     * @param string $message
     * @return mixed
     */
    public function logCronTaskRuntime(
        ScheduleEvent|CronUrlTaskMetaDtoWorker $scheduleTask,
        string                                 $execBatchId,
        string                                 $message,
        int                                    $pid = 0
    );
}