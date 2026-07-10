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
 * LLM / 规则混合路由 —— 可插拔的 Agent 选择策略。
 *
 * ---------------------------------------------------------------------------
 * 适用场景
 * ---------------------------------------------------------------------------
 *
 *   希望「由模型或自定义逻辑决定跑哪些 Agent」，同时在无 API / 单测时
 *   有可预测的关键词启发式回退。
 *
 * ---------------------------------------------------------------------------
 * 优先级
 * ---------------------------------------------------------------------------
 *
 *   1. 构造注入的 selector callable(RouterContext): list<string>
 *      — 生产可在此调用真实 LLM / 规则引擎，返回 agentId 列表
 *   2. 无 selector 时走 {@see heuristicRoute()} 关键词启发式
 *
 * ---------------------------------------------------------------------------
 * 启发式关键词（无 selector 时）
 * ---------------------------------------------------------------------------
 *
 *   query 含 code / api / github     → 尝试选 'coding'
 *   query 含 finance / cost / budget → 尝试选 'finance'
 *   均未命中或 query 为空            → 返回构造时的 availableAgents 全集
 *   命中后与 availableAgents 求交    → 避免选出未注册的 id
 *
 * 注意：启发式里的 'coding' / 'finance' 是约定名，须与 AgentParallelNode
 * 的 tasks 键一致（参见 multi_agent_research 示例）。
 *
 * @see StaticRouter  固定全选
 * @see RuleRouter    基于 State 表达式
 */
final class LLMRouter implements AgentRouterInterface
{
    /**
     * @param list<string> $availableAgents
     *        本路由认为「可选」的 agentId 全集（通常等于节点 tasks 的键；
     *        启发式求交与空 query 回退都用它）
     * @param (callable(RouterContext): list<string>)|null $selector
     *        自定义选择器；返回非 array 时视为未选中（空列表）
     */
    public function __construct(
        private readonly array $availableAgents,
        private $selector = null,
    ) {
    }

    /**
     * {@inheritdoc}
     *
     * @return list<string>
     */
    public function route(RouterContext $ctx): array
    {
        // 生产扩展点：在此接 LLM structured 输出 / 外部路由服务。
        if ($this->selector !== null) {
            $selected = ($this->selector)($ctx);

            // 规范化为 list（去掉关联键）；非数组则安全降级为空。
            return is_array($selected) ? array_values($selected) : [];
        }

        return $this->heuristicRoute($ctx);
    }

    /**
     * 无 LLM 时的关键词启发式（单测 / 本地无 Key 回退）。
     *
     * @return list<string>
     */
    private function heuristicRoute(RouterContext $ctx): array
    {
        $query = strtolower((string) $ctx->state->get('query', ''));

        // 无查询文本：无法推断意图，退回全部可用 Agent（与 StaticRouter 空列表行为类似）。
        if ($query === '') {
            return $this->availableAgents;
        }

        $selected = [];
        // 编码 / 仓库类意图。
        if (str_contains($query, 'code') || str_contains($query, 'api') || str_contains($query, 'github')) {
            $selected[] = 'coding';
        }
        // 财务 / 成本类意图。
        if (str_contains($query, 'finance') || str_contains($query, 'cost') || str_contains($query, 'budget')) {
            $selected[] = 'finance';
        }

        // 未命中任何关键词：同样回退全集，避免空路由。
        if ($selected === []) {
            return $this->availableAgents;
        }

        // 只保留既被启发式选中、又在 availableAgents 中声明的 id。
        return array_values(array_intersect($this->availableAgents, $selected));
    }
}
