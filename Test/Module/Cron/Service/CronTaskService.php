<?php

namespace Test\Module\Cron\Service;

use Swoolefy\Core\Schedule\ScheduleEvent;
use Swoolefy\Worker\Cron\CronProcess;
use Swoolefy\Worker\Dto\CronUrlTaskMetaDtoWorker;
use Test\Module\Cron\CronTaskEntity;
use Test\Module\Cron\CronTaskLogEntity;

/**
 * Cron Worker 侧任务拉取与运行日志写入。
 *
 * 实现 {@see \Swoolefy\Worker\Cron\CronTaskInterface}，供 CronProcess / Agent 使用：
 * - {@see fetchCronTask}：按节点 + 执行类型从 DB 取启用任务，并转成调度元数据
 * - {@see logCronTaskRuntime}：Worker 执行过程写 cron_task_log
 *
 * 管理端 CRUD 见 {@see CronTaskManagerService}。
 */
class CronTaskService implements \Swoolefy\Worker\Cron\CronTaskInterface
{
    /**
     * 按执行类型与节点拉取启用中的定时任务，并转为 Worker 可消费的元数据列表。
     *
     * - execType = {@see CronProcess::EXEC_FORK_TYPE}（shell/fork）→ {@see fetchShellCronTask}
     * - execType = {@see CronProcess::EXEC_URL_TYPE}（http）→ {@see fetchHttpCronTask}
     * - 其它类型抛 Exception
     *
     * 查询条件：status=1、node_id、exec_type。
     *
     * @param int $execType 执行类型（与 CronProcess / CronTaskPayloadDto 常量对齐）
     * @param int|string $nodeId Agent 节点 ID
     * @return list<array<string, mixed>> ScheduleEvent / CronUrlTaskMeta 的 toArray()
     * @throws \Swoolefy\Library\Exception\DbException
     * @throws \Exception exec_type 非法时
     */
    public function fetchCronTask(int $execType, $nodeId)
    {
        $list = CronTaskEntity::query()->field('*')->where([
            'status' => 1,
            'node_id' => $nodeId,
            'exec_type' => $execType,
        ])->select()->toArray();

        $entity = (new CronTaskEntity)->loadById(5);
        $entity->getConnection();


        if ($execType == CronProcess::EXEC_FORK_TYPE) {
            $taskList = $this->fetchShellCronTask($list);

            return $taskList;
        } elseif ($execType == CronProcess::EXEC_URL_TYPE) {
            $taskList = $this->fetchHttpCronTask($list);

            return $taskList;
        } else {
            throw new \Exception('exec_type error');
        }
    }

    /**
     * 将 DB 行转为 fork/shell 调度元数据（{@see ScheduleEvent}）。
     *
     * 填充 cron_task_id、日志回调类、表达式、脚本路径等；
     * 若 exec_script 含 `script.php` 且 `--c=`，标记为 swoolefy RUN_TYPE，否则 run_type 置空（其它语言脚本）。
     *
     * @param list<array<string, mixed>> $taskList 引用传入的原始行（当前实现未改写元素，仅遍历）
     * @return list<array<string, mixed>>
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

            // swoolefy 脚本：script.php + --c= 走框架 RUN_TYPE；其它语言脚本留空
            if (!empty($item['exec_script']) && (str_contains($item['exec_script'], 'script.php') && str_contains($item['exec_script'], '--c='))) {
                $cronForkTask->run_type = ScheduleEvent::RUN_TYPE;
            } else {
                $cronForkTask->run_type = '';
            }

            $newTaskList[] = $cronForkTask->toArray();
        }

        return $newTaskList;
    }

    /**
     * 将 DB 行转为 HTTP URL 调度元数据（{@see CronUrlTaskMetaDtoWorker}）。
     *
     * exec_script → url；http_body → params；http_headers → headers。
     * request_time_out：配置 &lt;120 时抬到 120；&gt;120 用配置值；未配置默认 120。
     *
     * @param list<array<string, mixed>> $taskList
     * @return list<array<string, mixed>>
     */
    public function fetchHttpCronTask(&$taskList)
    {
        $newTaskList = [];
        foreach ($taskList as $item) {
            $cronHttpTask = new CronUrlTaskMetaDtoWorker();
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

            if (!empty($item['http_request_time_out']) && $item['http_request_time_out'] < 120) {
                $cronHttpTask->request_time_out = 120;
            } elseif (!empty($item['http_request_time_out']) && $item['http_request_time_out'] > 120) {
                $cronHttpTask->request_time_out = $item['http_request_time_out'];
            } else {
                $cronHttpTask->request_time_out = 120;
            }

            $newTaskList[] = $cronHttpTask->toArray();
        }

        return $newTaskList;
    }

    /**
     * Worker 执行过程写入运行日志（cron_task_log）。
     *
     * task_item 存完整调度元数据快照，便于事后排查当时配置。
     *
     * @param ScheduleEvent|CronUrlTaskMetaDtoWorker $scheduleTask 当前执行的任务元数据
     * @param string $execBatchId 本轮执行批次 ID
     * @param string $message 运行消息（成功/失败/跳过等文案，管理端统计会解析）
     * @param int $pid 子进程 PID，无则 0
     */
    public function logCronTaskRuntime(
        ScheduleEvent|CronUrlTaskMetaDtoWorker $scheduleTask,
        string $execBatchId,
        string $message,
        int $pid = 0,
    ) {
        CronTaskLogEntity::query()->insert([
            'cron_id' => $scheduleTask->cron_task_id,
            'exec_batch_id' => $execBatchId,
            'pid' => $pid,
            'task_item' => $scheduleTask->toArray(),
            'message' => $message,
        ]);
    }
}
