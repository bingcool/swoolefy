<?php

declare(strict_types=1);

namespace Swoolefy\Support\AI\Builder;

use Swoolefy\Support\AI\Node\AINode;
use Swoolefy\Support\Neuron\Memory\MemoryFactory;
use Swoolefy\Support\Neuron\NeuronFactory;

/**
 * AINode 流式构建器（Fluent DSL）。
 *
 * 示例：
 *   AINode::make('ai_decision')
 *       ->agent(OrderDecisionAgent::class)
 *       ->structured(OrderDecisionDto::class, outputKey: 'decision')
 *       ->memory(threadIdKey: 'sessionId')
 *       ->build();
 *
 * Phase 1 测试可用 ->executor(callable) 注入 mock，无需 LLM API Key。
 *
 * @see swoolefyAI.md §4.3
 */
final class AINodeBuilder
{
    /** @var array<string, mixed> */
    private array $config = [];

    private function __construct(private readonly string $nodeId)
    {
    }

    /** 创建 Builder，指定节点 id。 */
    public static function make(string $nodeId): self
    {
        return new self($nodeId);
    }

    /**
     * 指定 Neuron Agent 类。
     *
     * @param class-string<\NeuronAI\Agent\Agent> $agentClass
     */
    public function agent(string $agentClass): self
    {
        $this->config['agent'] = $agentClass;

        return $this;
    }

    /**
     * 启用结构化输出，结果写入 state.data[outputKey]。
     *
     * @param class-string $dtoClass DTO 类名
     */
    public function structured(string $dtoClass, string $outputKey = 'output'): self
    {
        $this->config['structured'] = $dtoClass;
        $this->config['outputKey'] = $outputKey;

        return $this;
    }

    /**
     * 启用会话记忆，threadId 从 state.data[threadIdKey] 读取。
     */
    public function memory(?string $threadIdKey = 'sessionId', int $contextWindow = 50000): self
    {
        $this->config['memory'] = true;
        $this->config['threadIdKey'] = $threadIdKey;
        $this->config['contextWindow'] = $contextWindow;

        return $this;
    }

    /** 指定 prompt 在 state.data 中的键名。 */
    public function promptKey(string $key): self
    {
        $this->config['promptKey'] = $key;

        return $this;
    }

    /** LLM 温度参数。 */
    public function temperature(float $temperature): self
    {
        $this->config['temperature'] = $temperature;

        return $this;
    }

    /** 节点超时秒数。 */
    public function timeout(int $seconds): self
    {
        $this->config['timeout'] = $seconds;

        return $this;
    }

    /** 启用流式输出（与 structured 互斥）。 */
    public function stream(bool $enabled = true): self
    {
        $this->config['stream'] = $enabled;

        return $this;
    }

    /**
     * 指定 ai_model_providers 别名（见 {@see NeuronAiProviderName}）；可选 model 等构造参数覆盖。
     */
    public function provider(string $name, ?string $model = null): self
    {
        $this->config['provider'] = $name;
        if ($model !== null) {
            $this->config['model'] = $model;
        }

        return $this;
    }

    /**
     * 节点级 Provider 构造参数覆盖（与 neuron_ai.php 中对应别名配置合并）。
     *
     * @param array<string, mixed> $params
     */
    public function providerParams(array $params): self
    {
        $this->config['provider_params'] = $params;

        return $this;
    }

    /**
     * 声明式挂载 MCP Server（Phase 3）。
     *
     * @param list<string> $servers
     * @param array<string, list<string>>|null $only
     */
    public function mcp(array $servers, ?array $only = null, ?array $exclude = null): self
    {
        $this->config['mcpServers'] = $servers;
        if ($only !== null) {
            $this->config['mcpOnly'] = $only;
        }
        if ($exclude !== null) {
            $this->config['mcpExclude'] = $exclude;
        }

        return $this;
    }

    /**
     * 自定义 AI 执行逻辑（测试/自定义 Provider），跳过 Neuron Agent。
     *
     * @param callable(\Swoolefy\Support\Workflow\Engine\RunContext, \Swoolefy\Support\Workflow\State\WorkflowState): mixed $executor
     */
    public function executor(callable $executor): self
    {
        $this->config['executor'] = $executor;

        return $this;
    }

    /** 从数组配置创建 Builder（快捷构造器）。 */
    public static function fromArray(string $nodeId, array $config): self
    {
        $builder = new self($nodeId);
        $builder->config = $config;

        return $builder;
    }

    /** 构建 AINode 实例。 */
    public function build(
        ?MemoryFactory $memoryFactory = null,
        ?NeuronFactory $neuronFactory = null,
    ): AINode {
        return new AINode($this->nodeId, $this->config, $memoryFactory, null, $neuronFactory);
    }
}
