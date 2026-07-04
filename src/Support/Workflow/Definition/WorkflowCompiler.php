<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Definition;

use Swoolefy\Support\Workflow\Condition\ConditionEvaluatorInterface;
use Swoolefy\Support\Workflow\Exception\WorkflowCompileException;

/**
 * 工作流编译器：校验 Definition 并产出只读 {@see CompiledWorkflow}。
 *
 * 编译期检查：
 *   - 至少一个节点；边上节点 id 必须存在
 *   - 同一源节点不能混用固定边与条件边
 *   - 环检测（含所有条件分支可能路径）
 *   - 必须存在入口节点（入度为 0）
 *   - 不可达节点 → warning（不阻断）
 *
 * 产出物不可变，可在内存/Redis 中缓存。
 *
 * @see docs/swoolefyAI.md §3.3、§4.9.3
 */
final class WorkflowCompiler
{
    /**
     * @param ConditionEvaluatorInterface|null $conditionEvaluator 可选，用于条件重叠 warning
     */
    public function __construct(
        private readonly ?ConditionEvaluatorInterface $conditionEvaluator = null,
    ) {
    }

    /**
     * 编译工作流定义。
     *
     * @throws WorkflowCompileException 校验失败时抛出
     */
    public function compile(WorkflowDefinition $definition): CompiledWorkflow
    {
        $nodes = $definition->getNodes();
        if ($nodes === []) {
            throw new WorkflowCompileException('Workflow must contain at least one node');
        }

        $nodeIds = array_keys($nodes);
        $fixedEdges = [];
        $warnings = [];

        foreach ($definition->getEdges() as $edge) {
            $this->assertNodeExists($edge->from, $nodeIds, 'edge source');
            $this->assertNodeExists($edge->to, $nodeIds, 'edge target');

            if ($edge->type === EdgeType::ALWAYS) {
                if (isset($definition->getConditionalGroups()[$edge->from])) {
                    throw new WorkflowCompileException(
                        "Node {$edge->from} cannot have both fixed edge and conditional edges"
                    );
                }
                if (isset($fixedEdges[$edge->from])) {
                    throw new WorkflowCompileException("Node {$edge->from} has multiple fixed outgoing edges");
                }
                $fixedEdges[$edge->from] = $edge->to;
            }
        }

        $conditionalGroups = $definition->getConditionalGroups();
        foreach ($conditionalGroups as $from => $group) {
            if (isset($fixedEdges[$from])) {
                throw new WorkflowCompileException(
                    "Node {$from} cannot have both fixed edge and conditional edges"
                );
            }

            foreach (array_keys($group->branches) as $target) {
                $this->assertNodeExists($target, $nodeIds, 'conditional branch target');
            }

            if ($group->default !== null) {
                $this->assertNodeExists($group->default, $nodeIds, 'conditional default target');
            }

            $warnings = [...$warnings, ...$this->detectOverlappingConditions($group)];
        }

        $adjacency = $this->buildAdjacency($fixedEdges, $conditionalGroups);
        $cycleNodes = $this->findCycleNodes($nodeIds, $adjacency);
        if ($cycleNodes !== [] && !$this->cycleContainsPauseNode($cycleNodes, $nodes)) {
            throw new WorkflowCompileException('Workflow graph contains a cycle');
        }
        if ($cycleNodes !== []) {
            $warnings[] = 'Workflow contains HITL cycle interrupted by PauseNode';
        }

        $incoming = array_fill_keys($nodeIds, 0);
        foreach ($fixedEdges as $from => $to) {
            $incoming[$to]++;
        }
        foreach ($conditionalGroups as $group) {
            foreach (array_keys($group->branches) as $target) {
                $incoming[$target]++;
            }
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

        if ($entryNodes === []) {
            throw new WorkflowCompileException('Workflow has no entry node');
        }

        $reachable = $this->collectReachable($entryNodes, $adjacency);
        foreach ($nodeIds as $nodeId) {
            if (!isset($reachable[$nodeId])) {
                $warnings[] = "Node {$nodeId} is unreachable from entry nodes";
            }
        }

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

    /** 断言节点 id 存在于图中。 */
    private function assertNodeExists(string $nodeId, array $nodeIds, string $context): void
    {
        if (!in_array($nodeId, $nodeIds, true)) {
            throw new WorkflowCompileException("Unknown node {$nodeId} in {$context}");
        }
    }

    /** 构建邻接表（固定边 + 所有条件边目标）。 */
    private function buildAdjacency(array $fixedEdges, array $conditionalGroups): array
    {
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

    /** DFS 环检测，条件边所有可能目标均视为可达边。 */
    private function hasCycle(array $nodeIds, array $adjacency): bool
    {
        return $this->findCycleNodes($nodeIds, $adjacency) !== [];
    }

    /**
     * 查找环上节点 id 列表；无环返回空数组。
     *
     * @param list<string> $nodeIds
     *
     * @return list<string>
     */
    private function findCycleNodes(array $nodeIds, array $adjacency): array
    {
        $visited = [];
        $stack = [];
        $cycleNodes = [];

        $visit = function (string $node) use (&$visit, &$visited, &$stack, &$cycleNodes, $adjacency): bool {
            $visited[$node] = true;
            $stack[$node] = true;

            foreach ($adjacency[$node] ?? [] as $next) {
                if (!isset($visited[$next])) {
                    if ($visit($next)) {
                        $cycleNodes[] = $node;

                        return true;
                    }
                } elseif (isset($stack[$next])) {
                    $cycleNodes[] = $node;
                    $cycleNodes[] = $next;

                    return true;
                }
            }

            unset($stack[$node]);

            return false;
        };

        foreach ($nodeIds as $nodeId) {
            if (!isset($visited[$nodeId]) && $visit($nodeId)) {
                break;
            }
        }

        return array_values(array_unique($cycleNodes));
    }

    /** HITL 回路允许含 PauseNode（WAITING 打断无限执行）。 */
    private function cycleContainsPauseNode(array $cycleNodes, array $nodes): bool
    {
        foreach ($cycleNodes as $nodeId) {
            $node = $nodes[$nodeId] ?? null;
            if ($node instanceof \Swoolefy\Support\Workflow\Node\PauseNode) {
                return true;
            }
        }

        return false;
    }

    /** 从入口节点 BFS 收集可达节点。 */
    private function collectReachable(array $entryNodes, array $adjacency): array
    {
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

    /** 多分支条件可能重叠时产生编译 warning。 */
    private function detectOverlappingConditions(ConditionalEdgeGroup $group): array
    {
        if ($this->conditionEvaluator === null || count($group->branches) < 2) {
            return [];
        }

        return ["Conditional group from {$group->from} may have overlapping branches; first match wins"];
    }
}
