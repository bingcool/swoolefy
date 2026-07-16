<?php

declare(strict_types=1);

namespace Test\Module\Knowledge;

use Swoolefy\Support\Neuron\Embedding\EmbeddingFactory;
use Swoolefy\Support\Neuron\NeuronAiConfig;
use Swoolefy\Support\Neuron\NeuronAiVectorStoreName;
use Swoolefy\Support\Neuron\NeuronFactory;
use Swoolefy\Support\Rag\Factory\RagFactory;
use Swoolefy\Support\Rag\Factory\VectorStoreFactory;
use Swoolefy\Support\Rag\Retrieval\RetrievalService;
use Swoolefy\Support\Workflow\Engine\WorkflowEngine;
use Swoolefy\Support\Workflow\Engine\WorkflowEventDispatcherInterface;
use Swoolefy\Support\Workflow\WorkflowComponentFactory;
use Swoolefy\Support\Workflow\WorkflowRegistry;
use Test\Module\Knowledge\Workflow\KnowledgeQaWorkflow;

/**
 * Knowledge 模块本地工作流装配 —— 与 Order/Outdoor 同一模式。
 *
 * 职责：
 *   1. 注册本模块 workflowId（knowledge_qa）到独立 Registry
 *   2. 惰性创建本模块 RagFactory / RetrievalService / NeuronFactory
 *   3. Engine 与本 Registry 绑定同一 RunStore（谁启动谁查询）
 *
 * Demo / status 必须走本类；统一 API 经 WorkflowService::engineFor* 路由到此。
 *
 * @see KnowledgeQaWorkflow
 * @see Support\KnowledgeSeeder
 */
final class KnowledgeWorkflowService
{
    private static ?WorkflowRegistry $registry = null;

    private static ?NeuronFactory $neuronFactory = null;

    private static ?RagFactory $ragFactory = null;

    private static ?RetrievalService $retrievalService = null;

    public static function registry(): WorkflowRegistry
    {
        if (self::$registry === null) {
            self::$registry = new WorkflowRegistry();
            self::$registry->register(
                'knowledge_qa',
                static fn () => KnowledgeQaWorkflow::definition(
                    self::retrievalService(),
                    self::neuronFactory(),
                ),
            );
        }

        return self::$registry;
    }

    public static function engine(?WorkflowEventDispatcherInterface $events = null): WorkflowEngine
    {
        return WorkflowComponentFactory::engine(self::registry(), events: $events);
    }

    public static function neuronFactory(): NeuronFactory
    {
        if (self::$neuronFactory === null) {
            self::$neuronFactory = new NeuronFactory();
        }

        return self::$neuronFactory;
    }

    public static function ragFactory(): RagFactory
    {
        if (self::$ragFactory === null) {
            $basePath = sys_get_temp_dir() . '/swoolefy_knowledge_rag';
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

    public static function reset(): void
    {
        self::$registry = null;
        self::$neuronFactory = null;
        self::$ragFactory = null;
        self::$retrievalService = null;
        WorkflowComponentFactory::resetRunStores();
    }
}
