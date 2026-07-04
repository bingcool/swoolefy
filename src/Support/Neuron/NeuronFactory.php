<?php

declare(strict_types=1);

namespace Swoolefy\Support\Neuron;

use NeuronAI\Agent\Agent;
use NeuronAI\Providers\AIProviderInterface;
use Swoolefy\Support\Mcp\McpFactory;
use Swoolefy\Support\Neuron\Memory\MemoryFactoryInterface;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\State\WorkflowState;

/**
 * Neuron Agent 工厂 —— 注入 Memory、MCP Tools、默认 Provider 等 Swoolefy 基础设施。
 */
final class NeuronFactory
{
    /** @param (callable(class-string, WorkflowState, array): Agent)|null $agentFactory */
    public function __construct(
        private readonly MemoryFactoryInterface $memoryFactory,
        private readonly ?McpFactory $mcpFactory = null,
        private $agentFactory = null,
        private readonly ?NeuronProviderFactory $providerFactory = null,
    ) {
    }

    /**
     * @param class-string<Agent> $agentClass
     * @param array<string, mixed> $nodeConfig AINode 配置项
     */
    public function create(string $agentClass, WorkflowState $state, array $nodeConfig = []): Agent
    {
        if ($this->agentFactory !== null) {
            return ($this->agentFactory)($agentClass, $state, $nodeConfig);
        }

        /** @var Agent $agent */
        $agent = new $agentClass();

        $this->applyProvider($agent, $agentClass, $nodeConfig);

        if (($nodeConfig['memory'] ?? false) === true) {
            $threadKey = (string) ($nodeConfig['threadIdKey'] ?? 'sessionId');
            $threadId = (string) ($state->get($threadKey) ?: $state->get('runId') ?: uniqid('thread-', true));
            $contextWindow = (int) ($nodeConfig['contextWindow'] ?? 50000);
            $agent->setChatHistory($this->memoryFactory->forThread($threadId, $contextWindow));
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

        // Agent 未声明 provider() 且工厂无法解析时，必须注入，否则 resolveProvider() 会访问未初始化属性
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
