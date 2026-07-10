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

namespace Swoolefy\Support\Agent\Router;

use Swoolefy\Support\Agent\AgentRouterInterface;
use Swoolefy\Support\Agent\RouterContext;

/**
 * 固定 Agent 列表路由 —— 始终返回声明的 agentId 集合。
 *
 * ---------------------------------------------------------------------------
 * 适用场景
 * ---------------------------------------------------------------------------
 *
 *   - 演示 / 确定性并行（如 outdoor_cycling：weather + route + bike 必须全跑）
 *   - 不需要按 state 动态裁剪时的默认选择
 *
 * ---------------------------------------------------------------------------
 * 行为
 * ---------------------------------------------------------------------------
 *
 *   1. 构造时传入非空 agentIds → 原样返回（**不**与 availableAgents 求交）
 *   2. 构造时 agentIds 为空 → 回退为 ctx.availableAgents（节点注册的全部 task 键）
 *
 * 注意：静态列表若包含未在 AgentParallelNode tasks 中注册的 id，
 * Scheduler 侧通常会跳过或报错（取决于实现）；声明时请与 tasks 键对齐。
 *
 * @see AgentParallelNode
 * @see WorkflowDefinition::addAgentParallel()
 */
final class StaticRouter implements AgentRouterInterface
{
    /**
     * @param list<string> $agentIds 固定要并行的 agentId；空数组表示「用节点全部可用 Agent」
     */
    public function __construct(
        private readonly array $agentIds = [],
    ) {
    }

    /**
     * {@inheritdoc}
     *
     * @return list<string>
     */
    public function route(RouterContext $ctx): array
    {
        // 显式列表优先：调用方完全控制本轮跑谁（即使 availableAgents 更宽）。
        if ($this->agentIds !== []) {
            return $this->agentIds;
        }

        // 未声明列表时，等价于「全选节点已注册的 tasks」。
        return $ctx->availableAgents;
    }
}
