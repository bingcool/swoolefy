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

use Swoolefy\Support\Workflow\Node\NodeInterface;

/**
 * 编译后的只读工作流 —— DagScheduler 的拓扑索引。
 *
 * 由 {@see WorkflowCompiler} 一次性产出，运行时不可修改。
 * 缓存键建议：workflowId + version + compiled_hash。
 */
final class CompiledWorkflow
{
    public function __construct(
        private readonly string $workflowId,
        private readonly string $version,
        /** @var array<string, NodeInterface> */
        private readonly array $nodes,
        /** @var array<string, string> 源节点 => 固定目标 */
        private readonly array $fixedEdges,
        /** @var array<string, ConditionalEdgeGroup> */
        private readonly array $conditionalGroups,
        /** @var list<string> 入口节点（入度为 0） */
        private readonly array $entryNodes,
        /** @var list<string> 编译 warning */
        private readonly array $warnings,
        /** @var array<string, class-string> */
        private readonly array $schemas,
        /** @var list<class-string|string> */
        private readonly array $plugins,
        /** @var array<string, mixed> */
        private readonly array $metadata,
    ) {
    }

    /** 工作流 ID。 */
    public function workflowId(): string
    {
        return $this->workflowId;
    }

    /** 工作流版本。 */
    public function version(): string
    {
        return $this->version;
    }

    /** 按 id 获取节点实例，不存在返回 null。 */
    public function node(string $id): ?NodeInterface
    {
        return $this->nodes[$id] ?? null;
    }

    /** @return array<string, NodeInterface> 全部节点 */
    public function nodes(): array
    {
        return $this->nodes;
    }

    /** 获取源节点的固定出边目标，无条件边时返回 null。 */
    public function fixedEdge(string $from): ?string
    {
        return $this->fixedEdges[$from] ?? null;
    }

    /** 获取源节点的条件边组，无条件边时返回 null。 */
    public function conditionalGroup(string $from): ?ConditionalEdgeGroup
    {
        return $this->conditionalGroups[$from] ?? null;
    }

    /** @return list<string> 入口节点列表 */
    public function entryNodes(): array
    {
        return $this->entryNodes;
    }

    /** @return list<string> 编译期 warning 列表 */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** @return array<string, class-string> DTO schema 映射 */
    public function schemas(): array
    {
        return $this->schemas;
    }

    /** @return list<class-string|string> 声明的插件类 */
    public function plugins(): array
    {
        return $this->plugins;
    }

    /** @return array<string, mixed> 元数据 */
    public function metadata(): array
    {
        return $this->metadata;
    }
}
