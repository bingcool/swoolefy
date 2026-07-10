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

namespace Swoolefy\Support\Agent;

/**
 * Agent 路由策略 —— 决定并行执行哪些 Agent（调度对象是 Agent，不是 Node）。
 */
interface AgentRouterInterface
{
    /**
     * @return list<string> 待并行执行的 agentId 列表
     */
    public function route(RouterContext $ctx): array;
}
