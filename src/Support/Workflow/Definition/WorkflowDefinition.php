<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Definition;

use Swoolefy\Support\Workflow\Node\NodeInterface;

/**
 * 工作流纯声明对象 —— 只描述 DAG，不含 start()、不访问 Redis/Neuron。
 *
 * 职责：
 *   - 注册节点、固定边、条件边组
 *   - 附加 metadata、DTO schema、插件类名
 *
 * 运行时入口统一为 {@see \Swoolefy\Support\Workflow\Engine\WorkflowEngine::start()}。
 *
 * 边规则（编译期校验）：
 *   - 同一源节点不能同时有 addEdge 与 addConditionalEdges
 *   - 条件分支按声明顺序求值，首个为 true 的分支获胜
 *
 * @see swoolefyAI.md §4.1
 */
final class WorkflowDefinition
{
    /** @var array<string, NodeInterface> 节点 id => 节点实例 */
    private array $nodes = [];

    /** @var list<Edge> 边列表 */
    private array $edges = [];

    /** @var array<string, ConditionalEdgeGroup> 源节点 id => 条件边组 */
    private array $conditionalGroups = [];

    /** @var array<string, mixed> 元数据（owner、description 等） */
    private array $metadata = [];

    /** @var array<string, class-string> outputKey => DTO 类名 */
    private array $schemas = [];

    /** @var list<class-string|string> 声明级插件类名 */
    private array $plugins = [];

    private function __construct(
        private readonly string $id,
        private readonly string $version,
    ) {
    }

    /**
     * 创建工作流定义。
     *
     * @param string $id      工作流 ID
     * @param string $version 版本号
     */
    public static function create(string $id, string $version = '1.0.0'): self
    {
        return new self($id, $version);
    }

    /** 获取工作流 ID。 */
    public function id(): string
    {
        return $this->id;
    }

    /** 获取工作流版本。 */
    public function version(): string
    {
        return $this->version;
    }

    /**
     * 设置/合并元数据。
     *
     * @param array<string, mixed> $metadata
     */
    public function metadata(array $metadata): self
    {
        $this->metadata = array_replace($this->metadata, $metadata);

        return $this;
    }

    /** @return array<string, mixed> */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * 启用 Saga 分布式补偿（metadata.saga = true）。
     *
     * 节点 FAILED 时，WorkflowEngine 按 executedNodeIds 逆序调用 compensate()。
     * 各业务节点须实现幂等回滚逻辑（如退款、释放库存）。
     */
    public function enableSaga(bool $enabled = true): self
    {
        $this->metadata['saga'] = $enabled;

        return $this;
    }

    /**
     * 声明本工作流使用的插件类（文档/部署用，与全局 PluginManager 可叠加）。
     *
     * @param class-string|string ...$plugins
     */
    public function plugins(string ...$plugins): self
    {
        $this->plugins = array_values(array_unique([...$this->plugins, ...$plugins]));

        return $this;
    }

    /** @return list<class-string|string> */
    public function getPlugins(): array
    {
        return $this->plugins;
    }

    /**
     * 注册 DTO schema，供 WorkflowState::dto() 反序列化。
     * 示例：registerSchema('decision', OrderDecisionDto::class)
     */
    public function registerSchema(string $key, string $dtoClass): self
    {
        $this->schemas[$key] = $dtoClass;

        return $this;
    }

    /** @return array<string, class-string> */
    public function getSchemas(): array
    {
        return $this->schemas;
    }

    /**
     * 添加节点；节点 id 必须与 NodeInterface::id() 一致。
     */
    public function addNode(string $id, NodeInterface $node): self
    {
        if ($node->id() !== $id) {
            throw new \InvalidArgumentException("Node id mismatch: expected {$id}, got {$node->id()}");
        }

        $this->nodes[$id] = $node;

        return $this;
    }

    /** @return array<string, NodeInterface> */
    public function getNodes(): array
    {
        return $this->nodes;
    }

    /**
     * 添加无条件固定边：源节点 SUCCESS 后必定跳转到目标节点。
     */
    public function addEdge(string $from, string $to): self
    {
        $this->edges[] = new Edge($from, $to);

        return $this;
    }

    /**
     * 添加单条条件边（较少用，推荐 addConditionalEdges 多分支）。
     */
    public function addConditionalEdge(string $from, string $to, EdgeCondition|callable $condition): self
    {
        if (is_callable($condition) && !$condition instanceof EdgeCondition) {
            $condition = EdgeCondition::fromCallable($condition);
        }

        $this->edges[] = new Edge($from, $to, EdgeType::CONDITIONAL, $condition);

        return $this;
    }

    /**
     * 同源多分支条件边（AI 决策 / HITL 路由推荐用法）。
     *
     * @param array<string, EdgeCondition|callable> $branches 目标节点 id => 条件
     * @param string|null                           $default  无匹配时的兜底目标，避免运行失败
     */
    public function addConditionalEdges(string $from, array $branches, ?string $default = null): self
    {
        $normalized = [];
        foreach ($branches as $target => $condition) {
            if (is_callable($condition) && !$condition instanceof EdgeCondition) {
                $condition = EdgeCondition::fromCallable($condition);
            }
            $normalized[$target] = $condition;
        }

        $this->conditionalGroups[$from] = new ConditionalEdgeGroup($from, $normalized, $default);

        return $this;
    }

    /**
     * 添加多 Agent 并行节点（Phase 2）。
     *
     * @param array{
     *     router?: \Swoolefy\Support\Agent\AgentRouterInterface,
     *     scheduler: \Swoolefy\Support\Agent\AgentScheduler,
     *     agents: array<string, callable(\Swoolefy\Support\Agent\RouterContext, \Swoolefy\Support\Neuron\NeuronFactory): mixed>
     * } $config
     */
    public function addAgentParallel(string $nodeId, array $config): self
    {
        $scheduler = $config['scheduler'] ?? null;
        $agents = $config['agents'] ?? [];
        $router = $config['router'] ?? new \Swoolefy\Support\Agent\Router\StaticRouter(array_keys($agents));

        if (!$scheduler instanceof \Swoolefy\Support\Agent\AgentScheduler) {
            throw new \InvalidArgumentException('addAgentParallel requires scheduler instance');
        }

        $node = new \Swoolefy\Support\AI\Node\AgentParallelNode(
            $nodeId,
            $scheduler,
            $router,
            $agents,
        );

        return $this->addNode($nodeId, $node);
    }

    /**
     * {@see addAgentParallel()} 别名（文档 §4.6 addParallel）。
     *
     * @param array<string, mixed> $config
     */
    public function addParallel(string $nodeId, array $config): self
    {
        return $this->addAgentParallel($nodeId, $config);
    }

    /** @return list<Edge> */
    public function getEdges(): array
    {
        return $this->edges;
    }

    /** @return array<string, ConditionalEdgeGroup> */
    public function getConditionalGroups(): array
    {
        return $this->conditionalGroups;
    }
}
