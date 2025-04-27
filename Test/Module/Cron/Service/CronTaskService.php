<?php
namespace Test\Module\Cron\Service;

use Swoolefy\Core\Schedule\ScheduleEvent;
use Swoolefy\Worker\Cron\CronProcess;
use Swoolefy\Worker\Dto\CronUrlTaskMetaDto;
use Test\Module\Cron\CronTaskEntity;
use Test\Module\Cron\CronTaskLogEntity;

class CronTaskService implements \Swoolefy\Worker\Cron\CronTaskInterface {

    /**
     * @param int $execType
     * @return array
     * @throws \Common\Library\Exception\DbException
     */
    public function fetchCronTask(int $execType) {
        $list = CronTaskEntity::query()->field([
            'id',
            'name',
            'expression',
            'exec_script',
            'exec_type',
            'status',
            'with_block_lapping',
            'cron_between',
            'cron_skip',
            'updated_at' // 此字段非常重要
        ])->where([
            'status' => 1,
            'exec_type' => $execType
        ])->select()->toArray();

        if ($execType == CronProcess::EXEC_FORK_TYPE) {
            $taskList = $this->fetchShellCronTask($list);
            return $taskList;
        } else if ($execType ==  CronProcess::EXEC_URL_TYPE) {
            $taskList = $this->fetchHttpCronTask($list);
            return $taskList;
        } else {
            throw new \Exception('exec_type error');
        }
    }

    /**
     * @param $taskList
     * @return array
     */
    public function fetchShellCronTask(&$taskList)
    {
        $newTaskList = [];
        foreach ($taskList as $item) {
            $cronForkTask = ScheduleEvent::load($item);
            $cronForkTask->cron_task_id = $item['id'];
            $cronForkTask->cron_db_log_class = static::class;
            $cronForkTask->cron_meta_origin = ScheduleEvent::CRON_META_ORIGIN_DB;
            if (!empty($item['name'])) {
                $cronForkTask->cron_name = $item['name'];
            }

            if (!empty($item['expression'])) {
                $cronForkTask->cron_expression = $item['expression'];
            }

            if (!empty($item['exec_script'])) {
                $cronForkTask->exec_script = $item['exec_script'];
            }

            // swoolefy 需要特殊处理下
            if (!empty($item['exec_script']) && (str_contains($item['exec_script'], 'script.php') && str_contains($item['exec_script'], '--c='))) {
                $cronForkTask->run_type = ScheduleEvent::RUN_TYPE;
            }else {
                // 其他语言类型的脚本
                $cronForkTask->run_type = '';
            }

            $newTaskList[] = $cronForkTask->toArray();
        }

        return $newTaskList;
    }

    /**
     * @param $taskList
     * @return array
     */
    public function fetchHttpCronTask(&$taskList)
    {
        $newTaskList = [];
        foreach ($taskList as $item) {
            $cronHttpTask = new CronUrlTaskMetaDto();
            $cronHttpTask->cron_task_id = $item['id'];
            $cronHttpTask->cron_db_log_class = static::class;
            $cronHttpTask->cron_meta_origin = ScheduleEvent::CRON_META_ORIGIN_DB;
            if (!empty($item['name'])) {
                $cronHttpTask->cron_name = $item['name'];
            }

            if (!empty($item['expression'])) {
                $cronHttpTask->cron_expression = $item['expression'];
            }

            if (!empty($item['exec_script'])) {
                $cronHttpTask->url = $item['exec_script'];
            }

            if (!empty($item['http_method'])) {
                $cronHttpTask->method = $item['http_method'];
            }

            if (!empty($item['http_body'])) {
                $cronHttpTask->params = $item['http_body'];
            }

            if (!empty($item['http_headers'])) {
                $cronHttpTask->headers = $item['http_headers'];
            }

            $cronHttpTask->connect_time_out = 30;

            if (!empty($item['http_request_time_out']) && $item['http_request_time_out'] < 120 ) {
                $cronHttpTask->request_time_out = 120;
            } else if (!empty($item['http_request_time_out']) && $item['http_request_time_out'] > 120) {
                $cronHttpTask->request_time_out = $item['http_request_time_out'];
            } else {
                $cronHttpTask->request_time_out = 120;
            }

            $newTaskList[] = $cronHttpTask->toArray();
        }

        return $newTaskList;
    }

    /**
     * @param ScheduleEvent|CronUrlTaskMetaDto $scheduleTask
     * @param string $execBatchId
     * @param string $message
     * @return void
     */
    public function logCronTaskRuntime(
        ScheduleEvent|CronUrlTaskMetaDto $scheduleTask,
        string $execBatchId,
        string $message,
        int $pid = 0,
    )
    {
        CronTaskLogEntity::query()->insert([
            'cron_id' => $scheduleTask->cron_task_id,
            'exec_batch_id' => $execBatchId,
            'pid' => $pid,
            'task_item' => $scheduleTask->toArray(),
            'message' => $message
        ]);
    }
}