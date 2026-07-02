<?php

declare(strict_types=1);

namespace Test\Module\Workflow;

use Swoolefy\Support\Agent\AgentScheduler;
use Swoolefy\Support\Mcp\InMemoryMcpServerConfigRepository;
use Swoolefy\Support\Mcp\McpFactory;
use Swoolefy\Support\Mcp\McpProcessRunner;
use Swoolefy\Support\Mcp\McpServerConfig;
use Swoolefy\Support\Neuron\Embedding\EmbeddingFactory;
use Swoolefy\Support\Neuron\Memory\MemoryFactory;
use Swoolefy\Support\Neuron\NeuronFactory;
use Swoolefy\Support\Rag\Factory\RagFactory;
use Swoolefy\Support\Rag\Factory\VectorStoreFactory;
use Swoolefy\Support\Rag\Ingestion\IngestionPipeline;
use Swoolefy\Support\Rag\Retrieval\RetrievalService;
use Swoolefy\Support\Rag\Tool\RetrievalToolFactory;
use Swoolefy\Support\Workflow\WorkflowRegistry;
use Test\Module\Contract\Workflow\ContractReviewWorkflow;
use Test\Module\Knowledge\Workflow\KnowledgeQaWorkflow;
use Test\Module\Order\Workflow\OrderProcessingWorkflow;
use Test\Module\Order\Workflow\OrderSagaWorkflow;
use Test\Module\Research\Workflow\McpResearchWorkflow;
use Test\Module\Research\Workflow\MultiAgentResearchWorkflow;

/**
 * Workflow HTTP API 依赖装配 —— 注册 Phase 1~4 示例工作流与 RAG/MCP 工厂。
 *
 * 注册的 workflowId：
 *   order_processing、order_saga、multi_agent_research、contract_review、knowledge_qa、mcp_research
 *
 * Phase 4 扩展：
 *   - VectorStoreFactory::fromEnv() 向量库
 *   - IngestionPipeline / RetrievalToolFactory
 *   - McpFactory + InMemoryMcpServerConfigRepository + McpProcessRunner
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

    public static function registry(): WorkflowRegistry
    {
        if (self::$registry === null) {
            self::$registry = new WorkflowRegistry();
            self::$registry->register('order_processing', static fn () => OrderProcessingWorkflow::definition());
            self::$registry->register('order_saga', static fn () => OrderSagaWorkflow::definition());
            self::$registry->register('multi_agent_research', static fn () => MultiAgentResearchWorkflow::definition(
                self::agentScheduler(),
            ));
            self::$registry->register('contract_review', static fn () => ContractReviewWorkflow::definition());
            self::$registry->register('knowledge_qa', static fn () => KnowledgeQaWorkflow::definition(
                self::retrievalService(),
                self::neuronFactory(),
            ));
            self::$registry->register('mcp_research', static fn () => McpResearchWorkflow::definition(
                self::neuronFactory(),
            ));
        }

        return self::$registry;
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
            self::$ragFactory = new RagFactory(
                VectorStoreFactory::fromEnv($basePath),
                new EmbeddingFactory(),
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
                id: 'demo_http',
                tenantId: 'tenant_a',
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
            self::$neuronFactory = new NeuronFactory(
                new MemoryFactory(),
                self::mcpFactory(),
            );
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
        McpProcessRunner::reset();
    }
}
