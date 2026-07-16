<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Tests\Fixtures;

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

/**
 * Support Workflow 单测依赖装配 —— 不依赖 Test\Module\Workflow\WorkflowService。
 */
final class WorkflowTestServices
{
    public static function makeRagFactory(?string $basePath = null): RagFactory
    {
        $basePath ??= sys_get_temp_dir() . '/swoolefy_support_wf_rag_' . getmypid();
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

        return new RagFactory(
            new VectorStoreFactory($config, $basePath),
            new EmbeddingFactory($config),
        );
    }

    public static function makeRetrievalService(?RagFactory $ragFactory = null): RetrievalService
    {
        return new RetrievalService($ragFactory ?? self::makeRagFactory());
    }

    public static function makeIngestionPipeline(?RagFactory $ragFactory = null): IngestionPipeline
    {
        return ($ragFactory ?? self::makeRagFactory())->ingestionPipeline();
    }

    public static function makeRetrievalToolFactory(?RagFactory $ragFactory = null): RetrievalToolFactory
    {
        return new RetrievalToolFactory($ragFactory ?? self::makeRagFactory());
    }

    public static function makeNeuronFactory(): NeuronFactory
    {
        return new NeuronFactory();
    }

    public static function makeMcpFactory(): McpFactory
    {
        $repo = new InMemoryMcpServerConfigRepository();
        $repo->upsert(new McpServerConfig(
            server_id: 'demo_http',
            config: ['transport' => 'disabled', 'name' => 'demo_http'],
            description: 'Demo MCP server (disabled stub)',
        ));

        return new McpFactory(
            servers: [
                'github' => ['transport' => 'disabled', 'name' => 'github'],
            ],
            repository: $repo,
            processRunner: McpProcessRunner::fromEnv(),
        );
    }
}
