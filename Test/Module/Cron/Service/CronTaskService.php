<?php

namespace Test\Module\Cron\Service;

use Swoolefy\Core\Schedule\ScheduleEvent;
use Swoolefy\Worker\Cron\CronNodeLiveness;
use Swoolefy\Worker\Cron\CronProcess;
use Swoolefy\Worker\Dto\CronUrlTaskMetaDtoWorker;
use Test\Module\Cron\CronAgentNodeEntity;
use Test\Module\Cron\CronTaskEntity;
use Test\Module\Cron\CronTaskLogEntity;

/**
 * Cron Worker 侧任务拉取与运行日志写入。
 *
 * 实现 {@see \Swoolefy\Worker\Cron\CronTaskInterface}，供 CronProcess / Agent 使用：
 * - {@see fetchCronTask}：按节点 + 执行类型从 DB 取任务，并转成调度元数据
 * - {@see logCronTaskRuntime}：Worker 执行过程写 cron_task_log
 * - {@see ackNodeHeartbeat}：Worker 心跳 upsert cron_agent_node.last_heartbeat_at
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
        // 文档查询范围：node_id + 未软删。status 交给 Runtime Diff 做 ENABLE/DISABLE，
        // 不可在此处只取启用任务，否则 DISABLE 会被误当成 DELETE。
        $list = CronTaskEntity::queryNotDeleted()->field('*')->where([
            'node_id' => $nodeId,
            'exec_type' => $execType,
        ])->select()->toArray();

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
            $taskName = (string)($item['cron_name'] ?? $item['name'] ?? '');
            if ($taskName !== '') {
                $cronForkTask->cron_name = $taskName;
            }

            if (!empty($item['expression'])) {
                $cronForkTask->cron_expression = $item['expression'];
            }
            $cronForkTask->status = (int) ($item['status'] ?? 0);
            $cronForkTask->with_block_lapping = (int) ($item['with_block_lapping'] ?? 0);
            $cronForkTask->retry = max(0, (int) ($item['retry'] ?? 0));
            $cronForkTask->node_id = $item['node_id'] ?? null;
            $cronForkTask->cron_between = $item['cron_between'] ?? [];
            $cronForkTask->cron_skip = $item['cron_skip'] ?? [];

            if (!empty($item['command'])) {
                $cronForkTask->command = $item['command'];
                if (empty($item['exec_script'])) {
                    $cronForkTask->exec_script = $item['command'];
                }
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

            $arr = $cronForkTask->toArray();
            // 不改 cron_task.updated_at：手动执行标记来自独立队列表。
            // 带上全部 pending requestId，Worker 一条 request 对应一次 Execution。
            $pendingIds = $this->listPendingRunOnceIds((int) $item['id']);
            $arr['run_once_request_ids'] = $pendingIds;
            $arr['run_once_request_id'] = $pendingIds[0] ?? null;
            $arr['run_once_requested'] = $pendingIds !== [];
            $newTaskList[] = $arr;
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
            $taskName = (string)($item['cron_name'] ?? $item['name'] ?? '');
            if ($taskName !== '') {
                $cronHttpTask->cron_name = $taskName;
            }

            if (!empty($item['expression'])) {
                $cronHttpTask->cron_expression = $item['expression'];
            }
            $cronHttpTask->status = (int) ($item['status'] ?? 0);
            $cronHttpTask->with_block_lapping = (int) ($item['with_block_lapping'] ?? 0);
            $cronHttpTask->retry = max(0, (int) ($item['retry'] ?? 0));
            $cronHttpTask->node_id = $item['node_id'] ?? null;
            $cronHttpTask->cron_between = $item['cron_between'] ?? [];
            $cronHttpTask->cron_skip = $item['cron_skip'] ?? [];
            $cronHttpTask->command = $item['command'] ?? $cronHttpTask->url;

            if (!empty($item['command'])) {
                $cronHttpTask->url = $item['command'];
            } elseif (!empty($item['exec_script'])) {
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

            $arr = $cronHttpTask->toArray();
            $pendingIds = $this->listPendingRunOnceIds((int) $item['id']);
            $arr['run_once_request_ids'] = $pendingIds;
            $arr['run_once_request_id'] = $pendingIds[0] ?? null;
            $arr['run_once_requested'] = $pendingIds !== [];
            $newTaskList[] = $arr;
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

    /**
     * 是否有未消费的手动执行请求（供 fetcher 打标，CronManager Polling 消费）。
     */
    public function hasPendingRunOnce(int $cronTaskId): bool
    {
        return (new CronTaskManagerService())->hasPendingRunOnce($cronTaskId);
    }

    /**
     * 取出某任务当前最老的一条未消费手动执行请求 ID。
     */
    public function findOnePendingRunOnce(int $cronTaskId): ?int
    {
        return (new CronTaskManagerService())->findOnePendingRunOnce($cronTaskId);
    }

    /**
     * 列出某任务全部未消费的手动执行请求 ID（FIFO）。
     *
     * @return list<int>
     */
    public function listPendingRunOnceIds(int $cronTaskId): array
    {
        return (new CronTaskManagerService())->listPendingRunOnceIds($cronTaskId);
    }

    /**
     * Cron Worker 消费一条手动执行请求后清队列（按 request 主键，不是 cron_id）。
     */
    public function ackRunOnce(int $requestId): void
    {
        (new CronTaskManagerService())->ackRunOnce($requestId);
    }

    /**
     * Cron Worker 节点心跳：更新 last_heartbeat_at，并写入该节点自己的 heartbeat_interval。
     * 节点行不存在时按 nodeId 插入，避免 Admin 因缺行一直 offline。
     *
     * @param string|int $nodeId cron_agent_node.id
     * @param int $heartbeatInterval Worker args heartbeat_interval（秒）
     */
    public function ackNodeHeartbeat(string|int $nodeId, int $heartbeatInterval = CronNodeLiveness::DEFAULT_INTERVAL): void
    {
        $id = (int) $nodeId;
        if ($id <= 0) {
            return;
        }
        $interval = CronNodeLiveness::normalizeInterval($heartbeatInterval);
        $now = date('Y-m-d H:i:s');
        $node = (new CronAgentNodeEntity())->loadById($id);
        if ($node) {
            $node->last_heartbeat_at = $now;
            $node->heartbeat_interval = $interval;
            $node->save();

            return;
        }
        CronAgentNodeEntity::query()->insert([
            'id' => $id,
            'node_name' => 'node-' . $id,
            'node_ip' => '',
            'remark' => '',
            'last_heartbeat_at' => $now,
            'heartbeat_interval' => $interval,
        ]);
    }
}
