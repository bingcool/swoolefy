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

use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\Node\AbstractNode;
use Swoolefy\Support\Workflow\Node\NodeInterface;
use Throwable;

/**
 * Saga 补偿协调器 —— 分布式事务本地回滚编排。
 *
 * 触发条件（WorkflowEngine 内）：
 *   - WorkflowDefinition.enableSaga() → metadata.saga = true
 *   - 某节点返回 NodeStatus::FAILED
 *   - executedNodeIds 非空（已有成功节点需回滚）
 *
 * 补偿顺序：
 *   按 executedNodeIds **逆序** 调用 AbstractNode::compensate()
 *   例：validate → reserve → payment 失败后
 *        compensate payment → compensate reserve（validate 通常无副作用，可不实现）
 *
 * 技术要点：
 * - compensate 须幂等（可能重试或部分失败后再次调用）
 * - 补偿失败汇总为 WorkflowException，Run 状态为 FAILED（非 COMPENSATED）
 * - 与 HITL / Pause 无关；WAITING 不触发补偿
 *
 * @see docs/SwoolefyAI.md §4.2 onFail → compensate
 */
final class SagaCoordinator
{
    /**
     * 逆序补偿已成功执行的节点。
     *
     * @param WorkflowRun    $run             当前 Run（compensate 可读写 state）
     * @param list<string>   $executedNodeIds 按**正序执行**排列的节点 id 列表
     *
     * @throws WorkflowException 任一节点 compensate 抛错时，errors 汇总后抛出
     */
    public function compensate(WorkflowRun $run, array $executedNodeIds): SagaResult
    {
        $compensated = [];
        $errors = [];

        foreach (array_reverse($executedNodeIds) as $nodeId) {
            $node = $run->compiled->node($nodeId);
            if (!$this->shouldCompensate($node)) {
                continue;
            }

            try {
                $ctx = new RunContext($run->runId, $run->compiled);
                $node->compensate($ctx, $run->state);
                $compensated[] = $nodeId;
            } catch (Throwable $e) {
                $errors[$nodeId] = $e->getMessage();
            }
        }

        if ($errors !== []) {
            throw new WorkflowException(
                'Saga compensation failed: ' . json_encode($errors, JSON_UNESCAPED_UNICODE),
            );
        }

        return new SagaResult($compensated);
    }

    /** 仅 AbstractNode 子类参与补偿（有 compensate 默认空实现也可调用）。 */
    private function shouldCompensate(?NodeInterface $node): bool
    {
        return $node instanceof AbstractNode;
    }
}
