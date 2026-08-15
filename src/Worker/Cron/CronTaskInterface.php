<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

namespace Swoolefy\Worker\Cron;

use Swoolefy\Core\Schedule\ScheduleEvent;
use Swoolefy\Worker\Dto\CronUrlTaskMetaDtoWorker;

/**
 * Web Admin / 业务侧实现的 Cron 任务拉取与运行日志接口。
 *
 * CronManager 不直接依赖本接口：CronProcess 把 Closure task_list 包成 fetcher，
 * 把 logCronTaskRuntime 包成 CronManager 的 logWriter。
 * 业务类（如 Test\\Module\\Cron\\Service\\CronTaskService）实现本接口，
 * 作为 DB 拉取与 cron_task_log 写入的约定。
 *
 * fetchCronTask() 成功必须返回数组；抛异常视为 DB 故障，
 * CronManager::syncFromFetcher() 会保留 Last Known Good Runtime。
 *
 * @see CronProcess::createTaskFetcher()
 * @see CronProcess::logCronTaskRuntime()
 */
interface CronTaskInterface
{
    /**
     * 按 exec_type（Shell=1 / HTTP=2）与 nodeId 拉取任务行。
     * 实现应返回 list<array>；CronManager 会再交给 TaskDefinition::fromArray()。
     *
     * @param int $execType CronProcess::EXEC_FORK_TYPE | EXEC_URL_TYPE
     * @param mixed $nodeId 节点过滤；空表示不过滤
     * @return mixed 生产路径期望 array
     */
    public function fetchCronTask(int $execType, $nodeId);

    /**
     * 写入 cron_task_log。execBatchId 为空串表示配置变更（ADD/UPDATE/DELETE 等）。
     * pid 为子进程 PID；HTTP 任务传 0。
     *
     * @param ScheduleEvent|CronUrlTaskMetaDtoWorker $scheduleTask TaskDefinition::toLogDto() 的结果
     * @param string $execBatchId 本轮 ExecutionSnapshot::execBatchId
     * @param string $message 已格式化的中文/英文日志
     * @param int $pid Shell 子进程 PID
     * @return mixed
     */
    public function logCronTaskRuntime(
        ScheduleEvent|CronUrlTaskMetaDtoWorker $scheduleTask,
        string                                 $execBatchId,
        string                                 $message,
        int                                    $pid = 0
    );
}