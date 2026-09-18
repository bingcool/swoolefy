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

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Engine;

/**
 * Run 快照存储接口。
 *
 * saveIfStatus 仅比 status，保留给 HITL / Redis CAS 单测。
 * Engine 业务路径的状态迁移必须走 saveIfStatusAndRevision，避免
 * 「status 相同、字段已被别人改」的丢失更新。
 *
 * 返回值：true=CAS 成功；false=条件不匹配（conflict）。
 * Redis 超时、连接失败、Lua 失败、JSON 损坏必须抛异常，不得 return false。
 */
interface RunStoreInterface
{
    /** 保存或更新 Run 快照（无条件覆盖）。仅用于新建 Run。 */
    public function save(WorkflowRun $run): void;

    /**
     * 仅当持久化 revision == $expectedRevision 时写入。
     * 成功后持久化 revision 为 expected+1，并更新 $run->revision。
     */
    public function saveIfRevision(WorkflowRun $run, int $expectedRevision): bool;

    /**
     * 仅当持久化 status == $expectedStatus 且 revision == $expectedRevision 时写入。
     * 禁止拆成 saveIfStatus + saveIfRevision 两次调用。
     * 成功后持久化 revision 为 expected+1，并更新 $run->revision。
     */
    public function saveIfStatusAndRevision(
        WorkflowRun $run,
        RunStatus $expectedStatus,
        int $expectedRevision,
    ): bool;

    /**
     * 条件写入 —— 仅比对 status（不比对 revision）。
     *
     * 典型用法：历史 HITL 测试。Engine 新路径不要再用本方法做状态迁移。
     *
     * @return bool true=写入成功；false=expectedStatus 不匹配或 Run 不存在
     */
    public function saveIfStatus(WorkflowRun $run, RunStatus $expectedStatus): bool;

    /** 按 runId 查找，不存在返回 null。 */
    public function find(string $runId): ?WorkflowRun;
}
