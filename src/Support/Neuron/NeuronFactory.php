<?php

declare(strict_types=1);

namespace Swoolefy\Support\Neuron;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Providers\AIProviderInterface;
use Swoolefy\Support\Mcp\McpFactory;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\State\WorkflowState;

/**
 * Neuron Agent 工厂 —— 注入 Provider、MCP Tools。
 *
 * 会话记忆由业务 Agent 自行实现 {@see Agent::chatHistory()}，工厂不再强制注入 Memory。
 * 仅当 nodeConfig['chatHistory'] 显式传入实例时才会覆盖。
 *
 * @see https://docs.neuron-ai.dev/agent/chat-history-and-memory
 */
final class NeuronFactory
{
    /** @param (callable(class-string, WorkflowState, array): Agent)|null $agentFactory */
    public function __construct(
        private readonly ?McpFactory $mcpFactory = null,
        private $agentFactory = null,
        private readonly ?NeuronProviderFactory $providerFactory = null,
    ) {
    }

    /**
     * 无参构造 Agent 并 boot（适用于默认 InMemory chatHistory 的 Agent）。
     *
     * @param class-string<Agent>  $agentClass
     * @param array<string, mixed> $nodeConfig
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
     * 对已构造的 Agent 注入 Provider / MCP（不改写 chatHistory，除非显式传入）。
     *
     * 业务侧示例：
     *   $agent = new ChatAgent($threadId, $pdo);
     *   $factory->boot($agent, ['provider' => 'deepseek']);
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

    /** @param array<string, mixed> $nodeConfig */
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

    /** @param array<string, mixed> $nodeConfig */
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

        $tools = $this->mcpFactory->tools($servers, $only, $exclude);
        if ($tools !== []) {
            $agent->addTool($tools);
        }
    }
}
