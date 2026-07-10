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
 * 工作流 Run 整体生命周期状态。
 *
 * 状态流转（Phase 4 Saga 扩展）：
 *   RUNNING → COMPLETED | WAITING | FAILED | COMPENSATING
 *   COMPENSATING → COMPENSATED | FAILED
 *   COMPENSATED：业务已回滚，但 run.error 仍记录原始失败原因
 */
enum RunStatus: string
{
    /** 正在顺序执行 DAG 节点。 */
    case RUNNING = 'running';
    /** PauseNode 暂停，等待 WorkflowEngine::resume()。 */
    case WAITING = 'waiting';
    /** 所有节点成功执行完毕。 */
    case COMPLETED = 'completed';
    /** 节点失败且未启用 Saga，或 Saga 补偿本身失败。 */
    case FAILED = 'failed';
    /** 用户或系统调用 cancel()。 */
    case CANCELLED = 'cancelled';
    /** Saga 逆序 compensate 执行中（短暂中间态）。 */
    case COMPENSATING = 'compensating';
    /** Saga 补偿完成；原始触发失败的 error 仍保留在 Run.error。 */
    case COMPENSATED = 'compensated';
}
