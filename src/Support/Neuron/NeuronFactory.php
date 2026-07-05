<?php

declare(strict_types=1);

namespace Swoolefy\Support\Neuron;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Providers\AIProviderInterface;
use Swoolefy\Support\FrameworkContext;
use Swoolefy\Support\Mcp\McpFactory;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\State\WorkflowState;

/**
 * Neuron Agent 工厂 —— 统一注入 Provider 与 MCP Tools。
 *
 * 会话记忆由业务 Agent 自行实现 {@see Agent::chatHistory()}；
 * 仅当 nodeConfig['chatHistory'] 显式传入时才覆盖。
 *
 * MCP 多租户（Phase B）：
 *   tenantId 解析顺序 = nodeConfig['tenantId'] → FrameworkContext::getTenantId()
 *   传给 McpFactory::tools()，驱动 DB 仓储按租户过滤配置。
 *
 * @see https://docs.neuron-ai.dev/agent/chat-history-and-memory
 */
final class NeuronFactory
{
    /** @param (callable(class-string, WorkflowState, array): Agent)|null $agentFactory 单测/mock 注入点 */
    public function __construct(
        private readonly ?McpFactory $mcpFactory = null,
        private $agentFactory = null,
        private readonly ?NeuronProviderFactory $providerFactory = null,
    ) {
    }

    /**
     * 无参构造 Agent 并 boot。
     *
     * @param class-string<Agent>  $agentClass
     * @param array<string, mixed> $nodeConfig provider / mcpServers / tenantId 等
     */
    public function create(string $agentClass, WorkflowState $state, array $nodeConfig = []): Agent
    {
        if ($this->agentFactory !== null) {
            return ($this->agentFactory)($agentClass, $state, $nodeConfig);
        }

        /** @var Agent $agent */
        $agent = new $agentClass();

        return $this->boot($agent, $nodeConfig);
    }

    /**
     * 对已构造的 Agent 注入 Provider / MCP。
     *
     * @param array<string, mixed> $nodeConfig
     */
    public function boot(Agent $agent, array $nodeConfig = []): Agent
    {
        $agentClass = $agent::class;
        $this->applyProvider($agent, $agentClass, $nodeConfig);

        if (($nodeConfig['chatHistory'] ?? null) instanceof ChatHistoryInterface) {
            $agent->setChatHistory($nodeConfig['chatHistory']);
        }

        $this->attachMcpTools($agent, $nodeConfig);

        return $agent;
    }

    /** 注入 LLM Provider（节点 alias 或 default_provider）。 */
    private function applyProvider(Agent $agent, string $agentClass, array $nodeConfig): void
    {
        $factory = $this->providerFactory ?? new NeuronProviderFactory();

        $provider = $factory->createFromNodeConfig($nodeConfig);
        if ($provider === null && !NeuronProviderFactory::agentDeclaresCustomProvider($agentClass)) {
            $provider = $factory->createDefault();
        }

        if ($provider instanceof AIProviderInterface) {
            $agent->setAiProvider($provider);

            return;
        }

        if (!NeuronProviderFactory::agentDeclaresCustomProvider($agentClass)) {
            throw new WorkflowException(
                'No AI provider available. Configure API key and model for neuron.default_provider '
                . '(or any ai_model_providers entry) in neuron_ai.php / env, or pass "provider" in the request.',
            );
        }
    }

    /**
     * 挂载 MCP Tools 到 Agent。
     *
     * nodeConfig 键：
     *   mcpServers / mcp — Server 名列表
     *   mcpOnly / mcpExclude — 按 Server 过滤 tool
     *   tenantId — 显式租户；缺省时读 FrameworkContext（HTTP Header 透传）
     */
    private function attachMcpTools(Agent $agent, array $nodeConfig): void
    {
        if ($this->mcpFactory === null) {
            return;
        }

        $servers = $nodeConfig['mcpServers'] ?? $nodeConfig['mcp'] ?? null;
        if (!is_array($servers) || $servers === []) {
            return;
        }

        $only = is_array($nodeConfig['mcpOnly'] ?? null) ? $nodeConfig['mcpOnly'] : null;
        $exclude = is_array($nodeConfig['mcpExclude'] ?? null) ? $nodeConfig['mcpExclude'] : null;
        $tenantId = $nodeConfig['tenantId'] ?? FrameworkContext::getTenantId();

        $tools = $this->mcpFactory->tools($servers, $only, $exclude, is_string($tenantId) ? $tenantId : null);
        if ($tools !== []) {
            $agent->addTool($tools);
        }
    }
}
