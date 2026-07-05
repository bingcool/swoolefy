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
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\Engine\WorkflowEngine;
use Swoolefy\Support\Workflow\Engine\WorkflowEventDispatcherInterface;
use Swoolefy\Support\Workflow\WorkflowComponentFactory;
use Swoolefy\Support\Workflow\WorkflowRegistry;
use Test\Module\Contract\Workflow\ContractReviewWorkflow;
use Test\Module\Knowledge\Workflow\KnowledgeQaWorkflow;
use Test\Module\Order\Workflow\OrderProcessingWorkflow;
use Test\Module\Order\Workflow\OrderSagaWorkflow;
use Test\Module\Rag\RagService;
use Test\Module\Rag\Workflow\RagQaWorkflow;
use Test\Module\Research\Workflow\McpResearchWorkflow;
use Test\Module\Research\Workflow\MultiAgentResearchWorkflow;

/**
 * Workflow 模块依赖装配中心。
 *
 * 职责：
 *   1. 注册 Phase 1~4 示例工作流到 {@see WorkflowRegistry}（供通用 HTTP API 按 id 启动）
 *   2. 惰性创建共享依赖：NeuronFactory、AgentScheduler、RAG、MCP
 *   3. 提供 catalog / describe，便于演示控制器列出与探查 DAG
 *
 * 已注册 workflowId：
 *   order_processing、order_saga、multi_agent_research、mcp_research、
 *   contract_review、knowledge_qa、rag_qa
 *
 * 注意：Order / Research 模块另有专用 Demo 控制器，可注入 mock；
 * 本 Registry 使用各工作流的默认 definition（适合统一入口演示）。
 *
 * @see Test\Module\Workflow\Controller\WorkflowController
 * @see Test\Module\Workflow\README.md
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
     * 获取全局工作流注册表（首次调用时完成全部示例注册）。
     */
    public static function registry(): WorkflowRegistry
    {
        if (self::$registry === null) {
            self::$registry = new WorkflowRegistry();
            self::registerBuiltinWorkflows(self::$registry);
        }

        return self::$registry;
    }

    /**
     * 生产级 Engine：按 workflow.php 的 default_run_store 装配 RunStore（memory/redis/db）。
     *
     * HTTP 控制器应使用本方法，保证 status / resume / pauseTasks 跨 Worker 一致。
     */
    public static function engine(?WorkflowEventDispatcherInterface $events = null): WorkflowEngine
    {
        return WorkflowComponentFactory::engine(self::registry(), events: $events);
    }

    /**
     * 注册内置示例工作流。
     *
     * 工厂闭包惰性求值：只有 compiled() / definition() / catalog() 时才构建 DAG，
     * 避免启动阶段强依赖外部服务。
     */
    private static function registerBuiltinWorkflows(WorkflowRegistry $registry): void
    {
        // Phase 1：订单处理（AI 决策路由）
        $registry->register('order_processing', static fn () => OrderProcessingWorkflow::definition());
        // Phase 4：订单 Saga 补偿
        $registry->register('order_saga', static fn () => OrderSagaWorkflow::definition());
        // Phase 2：多 Agent 并行（默认走真实/Fake Agent，非 mock）
        $registry->register('multi_agent_research', static fn () => MultiAgentResearchWorkflow::definition(
            self::agentScheduler(),
        ));
        // Phase 3：MCP 研究（默认 research/summarize 为 stub，可离线）
        $registry->register('mcp_research', static fn () => McpResearchWorkflow::definition(
            self::neuronFactory(),
        ));
        // Phase 3：合同 HITL（legal_review 暂停，需 resume）
        $registry->register('contract_review', static fn () => ContractReviewWorkflow::definition());
        // Phase 3：知识库问答（依赖 RAG 检索服务）
        $registry->register('knowledge_qa', static fn () => KnowledgeQaWorkflow::definition(
            self::retrievalService(),
            self::neuronFactory(),
        ));
        // Rag 模块：retrieve → extractive answer
        $registry->register('rag_qa', static fn () => RagQaWorkflow::definition(
            self::retrievalService(),
        ));
    }

    /**
     * 工作流目录：id、版本、元数据、节点列表、示例入参。
     *
     * 供 GET /api/v1/workflow/list 使用。
     *
     * @return list<array<string, mixed>>
     */
    public static function catalog(): array
    {
        $registry = self::registry();
        $items = [];

        foreach ($registry->ids() as $workflowId) {
            $definition = $registry->definition($workflowId);
            $items[] = self::summarizeDefinition($definition);
        }

        return $items;
    }

    /**
     * 工作流详情：节点、固定边、条件边表达式、schema、插件。
     *
     * 供 GET /api/v1/workflow/describe?workflowId= 使用。
     *
     * @return array<string, mixed>
     *
     * @throws WorkflowException 未注册时抛出
     */
    public static function describe(string $workflowId): array
    {
        $definition = self::registry()->definition($workflowId);

        // 固定边：from => to
        $fixedEdges = [];
        foreach ($definition->getEdges() as $edge) {
            $fixedEdges[] = [
                'from' => $edge->from,
                'to' => $edge->to,
            ];
        }

        // 条件边组：from => branches[to => expression] + default
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
     * 各工作流推荐演示入参（文档 / list 接口展示，不强制校验）。
     *
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

    /**
     * 工作流所属业务模块（文档用）。
     */
    public static function moduleFor(string $workflowId): string
    {
        return match ($workflowId) {
            'order_processing', 'order_saga' => 'Order',
            'multi_agent_research', 'mcp_research' => 'Research',
            'contract_review' => 'Contract',
            'knowledge_qa' => 'Knowledge',
            'rag_qa' => 'Rag',
            default => 'Unknown',
        };
    }

    /**
     * 多 Agent 调度器（内部持有 NeuronFactory）。
     */
    public static function agentScheduler(): AgentScheduler
    {
        if (self::$agentScheduler === null) {
            self::$agentScheduler = new AgentScheduler(self::neuronFactory());
        }

        return self::$agentScheduler;
    }

    /**
     * RAG 工厂：向量库 + Embedding（路径默认系统临时目录）。
     */
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

    /** 检索服务（KnowledgeQaWorkflow 依赖）。 */
    public static function retrievalService(): RetrievalService
    {
        if (self::$retrievalService === null) {
            self::$retrievalService = new RetrievalService(self::ragFactory());
        }

        return self::$retrievalService;
    }

    /** 文档摄入管道。 */
    public static function ingestionPipeline(): IngestionPipeline
    {
        if (self::$ingestionPipeline === null) {
            self::$ingestionPipeline = self::ragFactory()->ingestionPipeline();
        }

        return self::$ingestionPipeline;
    }

    /** 检索 Tool 工厂（Agent Tool 场景）。 */
    public static function retrievalToolFactory(): RetrievalToolFactory
    {
        if (self::$retrievalToolFactory === null) {
            self::$retrievalToolFactory = new RetrievalToolFactory(self::ragFactory());
        }

        return self::$retrievalToolFactory;
    }

    /**
     * MCP 配置仓库（内存实现，演示用）。
     *
     * 预置 demo_http（transport=disabled），避免本地无 MCP 进程时报错。
     */
    public static function mcpRepository(): InMemoryMcpServerConfigRepository
    {
        if (self::$mcpRepository === null) {
            self::$mcpRepository = new InMemoryMcpServerConfigRepository();
            self::$mcpRepository->upsert(new McpServerConfig(
                id: 'demo_http',
                tenantId: 'tenant_a',
                config: ['transport' => 'disabled', 'name' => 'demo_http'],
                description: 'Demo MCP server (disabled stub)',
            ));
        }

        return self::$mcpRepository;
    }

    /**
     * MCP 工厂：声明可用 server（github 等），供 AINode::mcp() 绑定。
     */
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

    /**
     * Neuron 工厂：只注入 Provider / MCP。
     * 会话记忆由各 Agent::chatHistory() 自行声明。
     */
    public static function neuronFactory(): NeuronFactory
    {
        if (self::$neuronFactory === null) {
            self::$neuronFactory = new NeuronFactory(self::mcpFactory());
        }

        return self::$neuronFactory;
    }

    /**
     * 重置全部单例（单测隔离用）。
     */
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
     * 将 EdgeCondition 转为可读描述（表达式或类型名）。
     *
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
