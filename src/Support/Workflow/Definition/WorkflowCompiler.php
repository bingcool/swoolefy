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

namespace Swoolefy\Support\Workflow\Definition;

use Swoolefy\Support\Workflow\Condition\ConditionEvaluatorInterface;
use Swoolefy\Support\Workflow\Exception\WorkflowCompileException;
use Swoolefy\Support\Workflow\Node\PauseNode;

/**
 * 工作流编译器：校验 {@see WorkflowDefinition} 并产出只读 {@see CompiledWorkflow}。
 *
 * ---------------------------------------------------------------------------
 * 在三层架构中的位置
 * ---------------------------------------------------------------------------
 *
 *   Definition（可变声明） ──compile()──► CompiledWorkflow（不可变拓扑索引）
 *                                              │
 *                                              ▼
 *                                         WorkflowEngine / DagScheduler（运行时）
 *
 * Compiler **不**执行节点、不访问 Redis、不创建协程；只做静态图校验与索引构建。
 * 产出物可按 workflowId + version（+ hash）缓存，避免每次 start 重复编译。
 *
 * ---------------------------------------------------------------------------
 * 编译期硬错误（抛 {@see WorkflowCompileException}）
 * ---------------------------------------------------------------------------
 *
 *   1. 至少一个节点
 *   2. 边上引用的 from / to 必须已注册
 *   3. 同一源节点不能同时有「固定边」与「条件边组」
 *   4. 同一源节点最多一条固定出边（ALWAYS）
 *   5. 条件边组必须声明 default（避免运行时无匹配才失败）
 *   6. 图中存在环，且环上 **没有** PauseNode（无限循环风险）
 *   7. 必须恰好一个入口节点（入度为 0）；多入口与 Engine 单入口执行模型冲突
 *
 * ---------------------------------------------------------------------------
 * 编译期 warning（写入 CompiledWorkflow::warnings()，不阻断）
 * ---------------------------------------------------------------------------
 *
 *   - 从入口不可达的节点（死代码节点）
 *   - 含 PauseNode 的 HITL 回路（合法，但提示运维注意 resume）
 *   - 条件分支可能重叠（运行时「声明顺序首个为 true 获胜」）
 *
 * ---------------------------------------------------------------------------
 * 环检测策略
 * ---------------------------------------------------------------------------
 *
 * 邻接表把「固定边目标 + 条件边所有分支目标 + default」都视为可能后继，
 * 因此环检测覆盖 **所有可能运行路径**（偏保守：某条条件永远不走也会算进图）。
 * 例外：环上若含 {@see PauseNode}，运行时会 WAITING 打断，允许 HITL 回路，仅 warning。
 *
 * @see docs/SwoolefyAI.md §3.3、§4.9.3
 * @see WorkflowDefinition::addEdge()
 * @see WorkflowDefinition::addConditionalEdges()
 */
final class WorkflowCompiler
{
    /**
     * @param ConditionEvaluatorInterface|null $conditionEvaluator
     *        可选。当前仅用于「多分支可能重叠」的 warning 开关（有注入且分支≥2 时提示）；
     *        真正的条件求值在运行时由 DagScheduler + Evaluator 完成，不在编译期执行表达式。
     */
    public function __construct(
        private readonly ?ConditionEvaluatorInterface $conditionEvaluator = null,
    ) {
    }

    /**
     * 编译工作流定义 → 只读 CompiledWorkflow。
     *
     * 步骤概览：
 *   1. 校验节点非空
 *   2. 扫描固定边：节点存在性、与条件边互斥、单出边
 *   3. 扫描条件边组：目标存在性、强制 default、重叠 warning
 *   4. 建邻接表 → 环检测（PauseNode 例外）
 *   5. 计入度 → 恰好一个入口 → 可达性 warning
 *   6. 组装 CompiledWorkflow（拷贝 schemas / plugins / metadata）
 *
     * @throws WorkflowCompileException 任一硬约束失败
     */
    public function compile(WorkflowDefinition $definition): CompiledWorkflow
    {
        $nodes = $definition->getNodes();
        // --- 1) 空图直接失败：Engine 无法调度 ---
        if ($nodes === []) {
            throw new WorkflowCompileException('Workflow must contain at least one node');
        }

        $nodeIds = array_keys($nodes);
        /** @var array<string, string> $fixedEdges 源节点 id => 唯一固定目标 id */
        $fixedEdges = [];
        /** @var list<string> $warnings 非致命问题，随 CompiledWorkflow 返回给调用方 */
        $warnings = [];

        // --- 2) 固定边（EdgeType::ALWAYS）---
        // Definition 里 getEdges() 可能混有其它类型；此处只把 ALWAYS 收成 from=>to 映射，
        // 供 DagScheduler 在「无条件组」时直接跳转。
        foreach ($definition->getEdges() as $edge) {
            $this->assertNodeExists($edge->from, $nodeIds, 'edge source');
            $this->assertNodeExists($edge->to, $nodeIds, 'edge target');

            if ($edge->type === EdgeType::ALWAYS) {
                // 互斥：同一 from 若已声明条件边组，禁止再挂固定边（路由语义冲突）。
                if (isset($definition->getConditionalGroups()[$edge->from])) {
                    throw new WorkflowCompileException(
                        "Node {$edge->from} cannot have both fixed edge and conditional edges"
                    );
                }
                // 单出边：固定边场景下每个节点最多一个后继（并行扇出走 AgentParallel 等节点内部，不靠多条 ALWAYS）。
                if (isset($fixedEdges[$edge->from])) {
                    throw new WorkflowCompileException("Node {$edge->from} has multiple fixed outgoing edges");
                }
                $fixedEdges[$edge->from] = $edge->to;
            }
        }

        // --- 3) 条件边组（ConditionalEdgeGroup）---
        $conditionalGroups = $definition->getConditionalGroups();
        foreach ($conditionalGroups as $from => $group) {
            // 与固定边互斥（双向检查：上面查了「有条件组却加固定边」，这里查「有固定边却加条件组」）。
            if (isset($fixedEdges[$from])) {
                throw new WorkflowCompileException(
                    "Node {$from} cannot have both fixed edge and conditional edges"
                );
            }

            // 每个分支 target、以及 default，都必须是已注册节点。
            foreach (array_keys($group->branches) as $target) {
                $this->assertNodeExists($target, $nodeIds, 'conditional branch target');
            }

            // 强制 default：无匹配时若仅运行时报错，生产易 late failure。
            if ($group->default === null) {
                throw new WorkflowCompileException(
                    "Conditional edges from {$from} must declare a default target"
                );
            }
            $this->assertNodeExists($group->default, $nodeIds, 'conditional default target');

            // 多分支时提示「可能重叠」：编译期不求值表达式，只提醒运行时 first-match-wins。
            $warnings = [...$warnings, ...$this->detectOverlappingConditions($group)];
        }

        // --- 4) 环检测 ---
        // 邻接表 = 固定后继 ∪ 条件所有可能后继（含 default），覆盖全部可能路径。
        $adjacency = $this->buildAdjacency($fixedEdges, $conditionalGroups);
        $cycleNodes = $this->findCycleNodes($nodeIds, $adjacency);
        if ($cycleNodes !== [] && !$this->cycleContainsPauseNode($cycleNodes, $nodes)) {
            // 无 PauseNode 的环：运行时可能死循环，硬失败。
            throw new WorkflowCompileException('Workflow graph contains a cycle');
        }
        if ($cycleNodes !== []) {
            // 含 PauseNode：HITL 合法回路（WAITING → resume），降级为 warning。
            $warnings[] = 'Workflow contains HITL cycle interrupted by PauseNode';
        }

        // --- 5) 入度与入口节点 ---
        // 入度统计：谁被固定边 / 条件分支 / default 指向。
        // 入度 = 0 的节点视为入口；Engine 仅从唯一入口启动，多入口其余成死代码。
        $incoming = array_fill_keys($nodeIds, 0);
        foreach ($fixedEdges as $to) {
            // 入度只统计被指向的目标节点。
            $incoming[$to]++;
        }
        foreach ($conditionalGroups as $group) {
            foreach (array_keys($group->branches) as $target) {
                $incoming[$target]++;
            }
            // default 已在上方强制非 null；保留判断供静态分析
            if ($group->default !== null) {
                $incoming[$group->default]++;
            }
        }

        $entryNodes = [];
        foreach ($incoming as $nodeId => $count) {
            if ($count === 0) {
                $entryNodes[] = $nodeId;
            }
        }

        // 全图成环且无入度 0（例如 A→B→A 且无 Pause）时，上面环检测可能已抛错；
        // 若因 Pause 放过环但仍无入口，这里再拦一道。
        if ($entryNodes === []) {
            throw new WorkflowCompileException('Workflow has no entry node');
        }
        if (count($entryNodes) > 1) {
            throw new WorkflowCompileException(
                'Workflow must have exactly one entry node, found: ' . implode(', ', $entryNodes)
            );
        }

        // --- 6) 可达性（warning）---
        // 从所有入口 BFS；不可达节点不会被调度到，提示定义方删掉或补边。
        $reachable = $this->collectReachable($entryNodes, $adjacency);
        foreach ($nodeIds as $nodeId) {
            if (!isset($reachable[$nodeId])) {
                $warnings[] = "Node {$nodeId} is unreachable from entry nodes";
            }
        }

        // --- 7) 产出不可变 CompiledWorkflow ---
        // schemas / plugins / metadata 原样带入，供运行时 Typed State 与 PluginManager 使用。
        return new CompiledWorkflow(
            workflowId: $definition->id(),
            version: $definition->version(),
            nodes: $nodes,
            fixedEdges: $fixedEdges,
            conditionalGroups: $conditionalGroups,
            entryNodes: $entryNodes,
            warnings: $warnings,
            schemas: $definition->getSchemas(),
            plugins: $definition->getPlugins(),
            metadata: $definition->getMetadata(),
        );
    }

    /**
     * 断言节点 id 已在 Definition 中注册。
     *
     * @param list<string> $nodeIds 合法节点 id 列表
     * @param string       $context 错误上下文（edge source / conditional branch target 等），便于定位
     *
     * @throws WorkflowCompileException
     */
    private function assertNodeExists(string $nodeId, array $nodeIds, string $context): void
    {
        if (!in_array($nodeId, $nodeIds, true)) {
            throw new WorkflowCompileException("Unknown node {$nodeId} in {$context}");
        }
    }

    /**
     * 构建邻接表：from => [可能的后继…]
     *
     * 固定边贡献一条后继；条件边组把 **每一个** branch target 以及 default 都加入后继。
     * 用途：环检测、可达性分析（静态「可能路径」图，不是某次运行的实际路径）。
     *
     * @param array<string, string>                 $fixedEdges
     * @param array<string, ConditionalEdgeGroup>   $conditionalGroups
     *
     * @return array<string, list<string>>
     */
    private function buildAdjacency(array $fixedEdges, array $conditionalGroups): array
    {
        /** @var array<string, list<string>> $adjacency */
        $adjacency = [];

        foreach ($fixedEdges as $from => $to) {
            $adjacency[$from][] = $to;
        }

        foreach ($conditionalGroups as $group) {
            foreach (array_keys($group->branches) as $target) {
                $adjacency[$group->from][] = $target;
            }
            if ($group->default !== null) {
                $adjacency[$group->from][] = $group->default;
            }
        }

        return $adjacency;
    }

    /**
     * 是否存在环（{@see findCycleNodes()} 的布尔封装）。
     *
     * @param list<string>                 $nodeIds
     * @param array<string, list<string>>  $adjacency
     */
    private function hasCycle(array $nodeIds, array $adjacency): bool
    {
        return $this->findCycleNodes($nodeIds, $adjacency) !== [];
    }

    /**
     * DFS 三色思想环检测：找环上涉及的节点 id。
     *
     * - visited：已完全处理或处理中的节点
     * - stack：当前递归路径上的节点（灰节点）；若后继仍在 stack 中 → 后门边 → 有环
     *
     * 条件边的所有可能目标都在邻接表里，因此「仅某条条件会形成环」也会被检出（保守）。
     *
     * @param list<string>                $nodeIds
     * @param array<string, list<string>> $adjacency
     *
     * @return list<string> 环相关节点（去重）；无环返回 []
     */
    private function findCycleNodes(array $nodeIds, array $adjacency): array
    {
        /** @var array<string, true> $visited */
        $visited = [];
        /** @var array<string, true> $stack 当前 DFS 路径 */
        $stack = [];
        /** @var list<string> $cycleNodes */
        $cycleNodes = [];

        /**
         * @param string $node 当前访问节点
         *
         * @return bool 是否在本子树中发现环
         */
        $visit = function (string $node) use (&$visit, &$visited, &$stack, &$cycleNodes, $adjacency): bool {
            $visited[$node] = true;
            $stack[$node] = true;

            foreach ($adjacency[$node] ?? [] as $next) {
                if (!isset($visited[$next])) {
                    // 树边：继续深入；子树发现环则把当前节点记入环集合并向上传递。
                    if ($visit($next)) {
                        $cycleNodes[] = $node;

                        return true;
                    }
                } elseif (isset($stack[$next])) {
                    // 后门边：next 仍在当前路径上 → 存在环；记录边两端。
                    $cycleNodes[] = $node;
                    $cycleNodes[] = $next;

                    return true;
                }
                // 若 next 已 visited 且不在 stack：前向/横叉边，不构成新环。
            }

            // 回溯：离开当前路径。
            unset($stack[$node]);

            return false;
        };

        // 图可能不连通：对每个未访问节点启动 DFS；发现环即可停止收集（已够判定）。
        foreach ($nodeIds as $nodeId) {
            if (!isset($visited[$nodeId]) && $visit($nodeId)) {
                break;
            }
        }

        return array_values(array_unique($cycleNodes));
    }

    /**
     * 环上是否包含 PauseNode。
     *
     * HITL 典型结构：… → PauseNode(WAITING) → … → 回到上游；
     * 运行时 Pause 会打断自动推进，依赖 resume()，故允许此类「逻辑环」。
     *
     * @param list<string>                          $cycleNodes
     * @param array<string, \Swoolefy\Support\Workflow\Node\NodeInterface> $nodes
     */
    private function cycleContainsPauseNode(array $cycleNodes, array $nodes): bool
    {
        foreach ($cycleNodes as $nodeId) {
            $node = $nodes[$nodeId] ?? null;
            if ($node instanceof PauseNode) {
                return true;
            }
        }

        return false;
    }

    /**
     * 从入口节点集合 BFS，收集静态可达节点集合。
     *
     * @param list<string>                $entryNodes
     * @param array<string, list<string>> $adjacency
     *
     * @return array<string, true> 可达节点 id => true（isset 判断更快）
     */
    private function collectReachable(array $entryNodes, array $adjacency): array
    {
        /** @var array<string, true> $reachable */
        $reachable = [];
        $queue = $entryNodes;

        while ($queue !== []) {
            $node = array_shift($queue);
            if (isset($reachable[$node])) {
                continue;
            }
            $reachable[$node] = true;
            foreach ($adjacency[$node] ?? [] as $next) {
                if (!isset($reachable[$next])) {
                    $queue[] = $next;
                }
            }
        }

        return $reachable;
    }

    /**
     * 检测条件边组是否「可能」存在重叠分支。
     *
     * 当前实现不做符号/SMT 分析：只要注入了 conditionEvaluator 且分支数 ≥ 2，
     * 就给出统一 warning，提醒运行时按声明顺序 first-match-wins。
     * 未注入 evaluator 时跳过（避免无求值器场景刷屏）。
     *
     * @return list<string>
     */
    private function detectOverlappingConditions(ConditionalEdgeGroup $group): array
    {
        if ($this->conditionEvaluator === null || count($group->branches) < 2) {
            return [];
        }

        return ["Conditional group from {$group->from} may have overlapping branches; first match wins"];
    }
}
