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
 * saveIfStatus 用于 HITL resume 的 CAS（Compare-And-Swap）：
 * 仅当持久化层记录的 status 仍为 expectedStatus 时才写入，
 * 防止并发 resume 或 cancel 导致状态覆盖。
 */
interface RunStoreInterface
{
    /** 保存或更新 Run 快照（无条件覆盖）。 */
    public function save(WorkflowRun $run): void;

    /**
     * 条件写入 —— resume 并发安全的核心。
     *
     * 典型用法：WorkflowEngine::resume() 在将 status 改为 RUNNING 后，
     * 调用 saveIfStatus($run, RunStatus::WAITING)；若返回 false 说明
     * 其他 Worker 已 resume/cancel，当前请求应失败。
     *
     * @return bool true=写入成功；false=expectedStatus 不匹配或 Run 不存在
     */
    public function saveIfStatus(WorkflowRun $run, RunStatus $expectedStatus): bool;

    /** 按 runId 查找，不存在返回 null。 */
    public function find(string $runId): ?WorkflowRun;
}
