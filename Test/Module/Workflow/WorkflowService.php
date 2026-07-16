<?php

declare(strict_types=1);

namespace Test\Module\Workflow;

use Swoolefy\Support\Agent\AgentScheduler;
use Swoolefy\Support\Mcp\InMemoryMcpServerConfigRepository;
use Swoolefy\Support\Mcp\McpFactory;
use Swoolefy\Support\Mcp\McpProcessRunner;
use Swoolefy\Support\Mcp\McpServerConfig;
use Swoolefy\Support\Neuron\Embedding\EmbeddingFactory;
use Swoolefy\Support\Neuron\NeuronAiConfig;
use Swoolefy\Support\Neuron\NeuronAiVectorStoreName;
use Swoolefy\Support\Neuron\NeuronFactory;
use Swoolefy\Support\Rag\Factory\RagFactory;
use Swoolefy\Support\Rag\Factory\VectorStoreFactory;
use Swoolefy\Support\Rag\Ingestion\IngestionPipeline;
use Swoolefy\Support\Rag\Retrieval\RetrievalService;
use Swoolefy\Support\Rag\Tool\RetrievalToolFactory;
use Swoolefy\Support\Workflow\Definition\EdgeCondition;
use Swoolefy\Support\Workflow\Definition\WorkflowDefinition;
use Swoolefy\Support\Workflow\Engine\WorkflowEngine;
use Swoolefy\Support\Workflow\Engine\WorkflowEventDispatcherInterface;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\WorkflowComponentFactory;
use Swoolefy\Support\Workflow\WorkflowRegistry;
use Test\Module\Contract\ContractWorkflowService;
use Test\Module\Knowledge\KnowledgeWorkflowService;
use Test\Module\Order\OrderWorkflowService;
use Test\Module\Outdoor\OutdoorWorkflowService;
use Test\Module\Rag\RagService;
use Test\Module\Rag\RagWorkflowService;
use Test\Module\Research\ResearchWorkflowService;

/**
 * Workflow 模块联邦门面（目录 + 路由），不是业务工作流的 Runtime 真源。
 *
 * ## 所有权模型（模块本地装配，与 Order/Outdoor 同一模式）
 * | workflowId | 拥有模块 | Runtime |
 * |------------|----------|---------|
 * | order_processing / order_saga | Order | {@see OrderWorkflowService} |
 * | outdoor_cycling | Outdoor | {@see OutdoorWorkflowService} |
 * | multi_agent_research / mcp_research | Research | {@see ResearchWorkflowService} |
 * | rag_qa | Rag | {@see RagWorkflowService} |
 * | contract_review | Contract | {@see ContractWorkflowService} |
 * | knowledge_qa | Knowledge | {@see KnowledgeWorkflowService} |
 *
 * ## RunStore ↔ Registry
 * - 业务 Demo：只使用拥有模块的 engine()（谁启动谁查询）
 * - 统一 API：{@see engineFor()} / {@see engineForRun()} 路由到拥有方
 * - 联邦 {@see registry()} 仅 catalog/describe（definition 全部委托模块）
 *
 * Agent / MCP Demo 仍可复用本类 neuronFactory() / mcpFactory()（与工作流 Runtime 无关）。
 *
 * @see Controller\WorkflowController
 */
final class WorkflowService
{
    private static ?WorkflowRegistry $registry = null;

    private static ?AgentScheduler $agentScheduler = null;

    private static ?RagFactory $ragFactory = null;

    private static ?RetrievalService $retrievalService = null;

    private static ?IngestionPipeline $ingestionPipeline = null;

    private static ?RetrievalToolFactory $retrievalToolFactory = null;

    private static ?NeuronFactory $neuronFactory = null;

    private static ?McpFactory $mcpFactory = null;

    private static ?InMemoryMcpServerConfigRepository $mcpRepository = null;

    /**
     * 联邦注册表：模块工作流以 definition 委托，避免与模块 Registry 双份 DAG 定义。
     */
    public static function registry(): WorkflowRegistry
    {
        if (self::$registry === null) {
            self::$registry = new WorkflowRegistry();
            self::registerFederatedWorkflows(self::$registry);
        }

        return self::$registry;
    }

    /**
     * 联邦 Registry 绑定的 Engine（一般勿直接用于业务 start；请用 {@see engineFor()}）。
     */
    public static function engine(?WorkflowEventDispatcherInterface $events = null): WorkflowEngine
    {
        return WorkflowComponentFactory::engine(self::registry(), events: $events);
    }

    /**
     * 按 workflowId 返回拥有方 Engine（与模块 Demo 共用同一 Registry→RunStore 绑定）。
     */
    public static function engineFor(
        string $workflowId,
        ?WorkflowEventDispatcherInterface $events = null,
    ): WorkflowEngine {
        return match (self::moduleFor($workflowId)) {
            'Order' => OrderWorkflowService::engine($events),
            'Outdoor' => OutdoorWorkflowService::engine($events),
            'Research' => ResearchWorkflowService::engine($events),
            'Rag' => RagWorkflowService::engine($events),
            'Contract' => ContractWorkflowService::engine($events),
            'Knowledge' => KnowledgeWorkflowService::engine($events),
            default => self::engine($events),
        };
    }

    /**
     * 按 runId 解析拥有方 Engine（扫描各绑定 RunStore；用于 status/resume/cancel）。
     *
     * @throws WorkflowException 所有绑定 Store 均未找到该 runId
     */
    public static function engineForRun(
        string $runId,
        ?WorkflowEventDispatcherInterface $events = null,
    ): WorkflowEngine {
        foreach (self::runtimeRegistries() as $registry) {
            $store = WorkflowComponentFactory::runStore($registry);
            if ($store->find($runId) !== null) {
                return WorkflowComponentFactory::engine($registry, events: $events);
            }
        }

        throw new WorkflowException("Workflow run not found: {$runId}");
    }

    /**
     * 拥有方 Registry（definition 真源）；未知 id 回退联邦表。
     */
    public static function registryFor(string $workflowId): WorkflowRegistry
    {
        return match (self::moduleFor($workflowId)) {
            'Order' => OrderWorkflowService::registry(),
            'Outdoor' => OutdoorWorkflowService::registry(),
            'Research' => ResearchWorkflowService::registry(),
            'Rag' => RagWorkflowService::registry(),
            'Contract' => ContractWorkflowService::registry(),
            'Knowledge' => KnowledgeWorkflowService::registry(),
            default => self::registry(),
        };
    }

    /**
     * @return list<WorkflowRegistry>
     */
    private static function runtimeRegistries(): array
    {
        return [
            OrderWorkflowService::registry(),
            OutdoorWorkflowService::registry(),
            ResearchWorkflowService::registry(),
            RagWorkflowService::registry(),
            ContractWorkflowService::registry(),
            KnowledgeWorkflowService::registry(),
            self::registry(),
        ];
    }

    private static function registerFederatedWorkflows(WorkflowRegistry $registry): void
    {
        // 全部委托模块 Registry（单一 definition 真源；本类不再持有业务 DAG）
        $registry->register(
            'order_processing',
            static fn () => OrderWorkflowService::registry()->definition('order_processing'),
        );
        $registry->register(
            'order_saga',
            static fn () => OrderWorkflowService::registry()->definition('order_saga'),
        );
        $registry->register(
            'outdoor_cycling',
            static fn () => OutdoorWorkflowService::registry()->definition('outdoor_cycling'),
        );
        $registry->register(
            'multi_agent_research',
            static fn () => ResearchWorkflowService::registry()->definition('multi_agent_research'),
        );
        $registry->register(
            'mcp_research',
            static fn () => ResearchWorkflowService::registry()->definition('mcp_research'),
        );
        $registry->register(
            'rag_qa',
            static fn () => RagWorkflowService::registry()->definition('rag_qa'),
        );
        $registry->register(
            'contract_review',
            static fn () => ContractWorkflowService::registry()->definition('contract_review'),
        );
        $registry->register(
            'knowledge_qa',
            static fn () => KnowledgeWorkflowService::registry()->definition('knowledge_qa'),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function catalog(): array
    {
        $registry = self::registry();
        $items = [];

        foreach ($registry->ids() as $workflowId) {
            $items[] = self::summarizeDefinition($registry->definition($workflowId));
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws WorkflowException
     */
    public static function describe(string $workflowId): array
    {
        $definition = self::registryFor($workflowId)->definition($workflowId);

        $fixedEdges = [];
        foreach ($definition->getEdges() as $edge) {
            $fixedEdges[] = [
                'from' => $edge->from,
                'to' => $edge->to,
            ];
        }

        $conditionalEdges = [];
        foreach ($definition->getConditionalGroups() as $group) {
            $branches = [];
            foreach ($group->branches as $to => $condition) {
                $branches[$to] = self::describeCondition($condition);
            }
            $conditionalEdges[] = [
                'from' => $group->from,
                'branches' => $branches,
                'default' => $group->default,
            ];
        }

        $summary = self::summarizeDefinition($definition);
        $summary['nodes'] = array_map(
            static fn (string $id): array => [
                'id' => $id,
                'class' => $definition->getNodes()[$id]::class,
            ],
            array_keys($definition->getNodes()),
        );
        $summary['fixedEdges'] = $fixedEdges;
        $summary['conditionalEdges'] = $conditionalEdges;
        $summary['schemas'] = $definition->getSchemas();
        $summary['plugins'] = $definition->getPlugins();

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    public static function demoInputFor(string $workflowId): array
    {
        return match ($workflowId) {
            'order_processing' => [
                'orderId' => 'ORD-WF-10001',
                'userId' => 'u1',
                'amount' => 199.0,
                'currency' => 'CNY',
            ],
            'order_saga' => [
                'orderId' => 'ORD-WF-SAGA-1',
                'userId' => 'u1',
                'amount' => 50.0,
            ],
            'multi_agent_research' => [
                'query' => 'Analyze swoolefy workflow design',
            ],
            'outdoor_cycling' => [
                'destination' => '深圳湾公园',
                'weatherHint' => 'sunny',
            ],
            'mcp_research' => [
                'query' => 'urgent security patch review',
            ],
            'contract_review' => [
                'contractBrief' => 'SaaS annual subscription for Acme Corp',
            ],
            'knowledge_qa' => [
                'question' => 'What is the refund policy?',
            ],
            'rag_qa' => [
                'question' => 'What is RAG in swoolefy?',
                'knowledgeBase' => RagService::DEFAULT_KNOWLEDGE_BASE,
            ],
            default => [],
        };
    }

    public static function moduleFor(string $workflowId): string
    {
        return match ($workflowId) {
            'order_processing', 'order_saga' => 'Order',
            'multi_agent_research', 'mcp_research' => 'Research',
            'outdoor_cycling' => 'Outdoor',
            'contract_review' => 'Contract',
            'knowledge_qa' => 'Knowledge',
            'rag_qa' => 'Rag',
            default => 'Unknown',
        };
    }

    public static function agentScheduler(): AgentScheduler
    {
        if (self::$agentScheduler === null) {
            self::$agentScheduler = new AgentScheduler(self::neuronFactory());
        }

        return self::$agentScheduler;
    }

    public static function ragFactory(): RagFactory
    {
        if (self::$ragFactory === null) {
            $basePath = sys_get_temp_dir() . '/swoolefy_rag';
            $config = NeuronAiConfig::fromArray([
                'rag' => [
                    'default_vector_store' => NeuronAiVectorStoreName::FILE,
                    'embedding_dimension' => 1536,
                    'allow_fake_embeddings' => true,
                    'require_tenant_isolation' => false,
                    'vector_stores' => [
                        NeuronAiVectorStoreName::FILE => ['path' => $basePath],
                    ],
                ],
            ]);
            self::$ragFactory = new RagFactory(
                new VectorStoreFactory($config, $basePath),
                new EmbeddingFactory($config),
            );
        }

        return self::$ragFactory;
    }

    public static function retrievalService(): RetrievalService
    {
        if (self::$retrievalService === null) {
            self::$retrievalService = new RetrievalService(self::ragFactory());
        }

        return self::$retrievalService;
    }

    public static function ingestionPipeline(): IngestionPipeline
    {
        if (self::$ingestionPipeline === null) {
            self::$ingestionPipeline = self::ragFactory()->ingestionPipeline();
        }

        return self::$ingestionPipeline;
    }

    public static function retrievalToolFactory(): RetrievalToolFactory
    {
        if (self::$retrievalToolFactory === null) {
            self::$retrievalToolFactory = new RetrievalToolFactory(self::ragFactory());
        }

        return self::$retrievalToolFactory;
    }

    public static function mcpRepository(): InMemoryMcpServerConfigRepository
    {
        if (self::$mcpRepository === null) {
            self::$mcpRepository = new InMemoryMcpServerConfigRepository();
            self::$mcpRepository->upsert(new McpServerConfig(
                server_id: 'demo_http',
                config: ['transport' => 'disabled', 'name' => 'demo_http'],
                description: 'Demo MCP server (disabled stub)',
            ));
        }

        return self::$mcpRepository;
    }

    public static function mcpFactory(): McpFactory
    {
        if (self::$mcpFactory === null) {
            self::$mcpFactory = new McpFactory(
                servers: [
                    'github' => ['transport' => 'disabled', 'name' => 'github'],
                ],
                repository: self::mcpRepository(),
                processRunner: McpProcessRunner::fromEnv(),
            );
        }

        return self::$mcpFactory;
    }

    public static function neuronFactory(): NeuronFactory
    {
        if (self::$neuronFactory === null) {
            self::$neuronFactory = new NeuronFactory(self::mcpFactory());
        }

        return self::$neuronFactory;
    }

    public static function reset(): void
    {
        self::$registry = null;
        self::$agentScheduler = null;
        self::$ragFactory = null;
        self::$retrievalService = null;
        self::$ingestionPipeline = null;
        self::$retrievalToolFactory = null;
        self::$neuronFactory = null;
        self::$mcpFactory = null;
        self::$mcpRepository = null;
        OrderWorkflowService::reset();
        OutdoorWorkflowService::reset();
        ResearchWorkflowService::reset();
        RagWorkflowService::reset();
        ContractWorkflowService::reset();
        KnowledgeWorkflowService::reset();
        WorkflowComponentFactory::resetRunStores();
        McpProcessRunner::reset();
    }

    /**
     * @return array<string, mixed>
     */
    private static function summarizeDefinition(WorkflowDefinition $definition): array
    {
        $metadata = $definition->getMetadata();

        return [
            'workflowId' => $definition->id(),
            'version' => $definition->version(),
            'module' => self::moduleFor($definition->id()),
            'description' => (string) ($metadata['description'] ?? ''),
            'owner' => (string) ($metadata['owner'] ?? ''),
            'saga' => (bool) ($metadata['saga'] ?? false),
            'metadata' => $metadata,
            'nodeIds' => array_keys($definition->getNodes()),
            'demoInput' => self::demoInputFor($definition->id()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function describeCondition(EdgeCondition $condition): array
    {
        return [
            'type' => $condition->type,
            'expression' => $condition->expression,
            'jsonLogic' => $condition->jsonLogic,
        ];
    }
}
