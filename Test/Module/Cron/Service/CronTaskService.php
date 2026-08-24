<?php

namespace Test\Module\Cron\Service;

use Swoolefy\Core\Schedule\ScheduleEvent;
use Swoolefy\Worker\Cron\CronNodeLiveness;
use Swoolefy\Worker\Cron\CronProcess;
use Swoolefy\Worker\Cron\ExecutionStatus;
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
        $pendingByTaskId = $this->listPendingRunOnceIdsByCronTaskIds(
            array_map(static fn (array $item): int => (int) ($item['id'] ?? 0), $list)
        );

        if ($execType == CronProcess::EXEC_FORK_TYPE) {
            $taskList = $this->fetchShellCronTask($list, $pendingByTaskId);
            return $taskList;
        } elseif ($execType == CronProcess::EXEC_URL_TYPE) {
            $taskList = $this->fetchHttpCronTask($list, $pendingByTaskId);
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
     * @param array<int, list<int>> $pendingByTaskId key=cron_task.id，value=未消费 request_id 列表
     * @return list<array<string, mixed>>
     */
    public function fetchShellCronTask(&$taskList, array $pendingByTaskId = [])
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
            if (!empty($cronForkTask->exec_script) && (str_contains($cronForkTask->exec_script, 'script.php') && str_contains($cronForkTask->exec_script, '--c='))) {
                $cronForkTask->run_type = ScheduleEvent::RUN_TYPE;
            } else {
                $cronForkTask->run_type = '';
            }

            $arr = $cronForkTask->toArray();
            // 不改 cron_task.updated_at：手动执行标记来自独立队列表。
            // 带上全部 pending requestId，Worker 一条 request 对应一次 Execution。
            $pendingIds = $pendingByTaskId[(int) ($item['id'] ?? 0)] ?? [];
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
     * request_time_out：配置值 &gt;0 时严格使用；0/空回退默认 120。
     *
     * @param list<array<string, mixed>> $taskList
     * @param array<int, list<int>> $pendingByTaskId key=cron_task.id，value=未消费 request_id 列表
     * @return list<array<string, mixed>>
     */
    public function fetchHttpCronTask(&$taskList, array $pendingByTaskId = [])
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
            $configuredTimeout = isset($item['http_request_time_out']) ? (int) $item['http_request_time_out'] : 0;
            $cronHttpTask->request_time_out = $configuredTimeout > 0 ? $configuredTimeout : 120;

            $arr = $cronHttpTask->toArray();
            $pendingIds = $pendingByTaskId[(int) ($item['id'] ?? 0)] ?? [];
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
     * 同一 exec_batch_id 只保留一条 Execution 行：开始为 RUNNING，结束 UPDATE 终态。
     * 配置变更（exec_batch_id 空串）始终 INSERT，不写入执行 status。
     * $execution 只覆盖显式给出的结构化字段，避免 PID 回调把 status 打回 0。
     *
     * @param ScheduleEvent|CronUrlTaskMetaDtoWorker $scheduleTask 当前执行的任务元数据
     * @param string $execBatchId 本轮执行批次 ID
     * @param string $message 人类可读运行信息（不用于 taskStats）
     * @param int $pid 子进程 PID，无则 0
     * @param array<string, mixed> $execution 结构化字段
     */
    public function logCronTaskRuntime(
        ScheduleEvent|CronUrlTaskMetaDtoWorker $scheduleTask,
        string $execBatchId,
        string $message,
        int $pid = 0,
        array $execution = [],
    ) {
        $cronId = (int) $scheduleTask->cron_task_id;
        $row = [
            'cron_id' => $cronId,
            'exec_batch_id' => $execBatchId,
            'pid' => $pid,
            'task_item' => $scheduleTask->toArray(),
            'message' => $message,
        ];
        foreach (['status', 'trigger_type', 'scheduled_at', 'started_at', 'finished_at', 'duration_ms', 'exit_code', 'http_status'] as $field) {
            if (array_key_exists($field, $execution)) {
                $row[$field] = $execution[$field];
            }
        }

        if ($execBatchId !== '') {
            $existing = CronTaskLogEntity::queryNotDeleted()
                ->where([
                    'cron_id' => $cronId,
                    'exec_batch_id' => $execBatchId,
                ])
                ->order('id', 'asc')
                ->find();
            if ($existing) {
                $id = is_array($existing) ? (int) ($existing['id'] ?? 0) : (int) $existing->id;
                if ($id > 0) {
                    unset($row['cron_id'], $row['exec_batch_id']);
                    CronTaskLogEntity::query()->where(['id' => $id])->update($row);

                    return;
                }
            }
            if (!isset($row['status'])) {
                $row['status'] = ExecutionStatus::RUNNING;
            }
        }

        CronTaskLogEntity::query()->insert($row);
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
     * 批量列出多任务未消费请求，避免 fetchCronTask 的 N+1 查询。
     *
     * @param list<int> $cronTaskIds
     * @return array<int, list<int>>
     */
    public function listPendingRunOnceIdsByCronTaskIds(array $cronTaskIds): array
    {
        return (new CronTaskManagerService())->listPendingRunOnceIdsByCronTaskIds($cronTaskIds);
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
        // 已软删节点不再自动插入，避免 DELETE /nodes 后被心跳「复活」
        $trashed = CronAgentNodeEntity::withoutTrashed()->where('id', $id)->limit(1)->find();
        if (is_array($trashed) && $trashed !== []) {
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
