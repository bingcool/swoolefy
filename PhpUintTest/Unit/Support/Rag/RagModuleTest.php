<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

declare(strict_types=1);

namespace PhpUintTest\Unit\Support\Rag;

/**
 * RAG 模块回归测试（无需真实 Milvus / PostgreSQL / 外网 Embedding）。
 *
 * ## 覆盖范围
 * | 区域 | 要点 |
 * |------|------|
 * | VectorStoreFactory | FILE 模式、知识库名净化、自定义 alias/driver、未知 alias/driver 抛错 |
 * | IngestionPipeline / RetrievalService | ingestTexts/ingest、空文本跳过、检索命中形状 |
 * | RagIngestNode / RagRetrieveNode | Workflow state 传递 tenantId |
 * | RagIngestDispatcher | 队列模式 producer/consumer、消费后可检索 |
 * | MilvusVectorStore / MilvusFilterExpr | make 参数、collection 名净化、过滤表达式转义 |
 * | PgVectorStore | make 参数、非法表名/metric 拒绝 |
 * | NeuronAiConfig | Milvus / PgVector 配置段读取 |
 *
 * ## 运行
 * ```bash
 * ./vendor/bin/phpunit PhpUintTest/Unit/Support/Rag/RagModuleTest.php
 * ```
 */

use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\FileVectorStore;
use Swoolefy\Support\Neuron\Embedding\EmbeddingFactory;
use Swoolefy\Support\Neuron\NeuronAiConfig;
use Swoolefy\Support\Neuron\NeuronAiVectorStoreName;
use Swoolefy\Support\Rag\Factory\RagFactory;
use Swoolefy\Support\Rag\Factory\VectorStoreFactory;
use Swoolefy\Support\Rag\Ingestion\ConfigurableRagIngestQueue;
use Swoolefy\Support\Rag\Ingestion\IngestResult;
use Swoolefy\Support\Rag\Ingestion\IngestionPipeline;
use Swoolefy\Support\Rag\Ingestion\RagIngestDispatcher;
use Swoolefy\Support\Rag\Ingestion\RagIngestJob;
use Swoolefy\Support\Rag\Node\RagIngestNode;
use Swoolefy\Support\Rag\Node\RagRetrieveNode;
use Swoolefy\Support\Rag\Retrieval\RetrievalService;
use Swoolefy\Support\Rag\Store\MilvusFilterExpr;
use Swoolefy\Support\Rag\Store\MilvusVectorStore;
use Swoolefy\Support\Rag\Store\PgVectorStore;
use Swoolefy\Support\Workflow\Definition\WorkflowDefinition;
use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\RunContext;
use Swoolefy\Support\Workflow\Node\ClosureNode;
use Swoolefy\Support\Workflow\State\WorkflowState;
use Swoolefy\Support\Workflow\WorkflowComponentFactory;
use Swoolefy\Support\Workflow\WorkflowConfig;
use RuntimeException;
use PhpUintTest\TestCase;
use PDO;

final class RagModuleTest extends TestCase
{
    /**
     * 构造带 FILE 向量库与 allow_fake_embeddings 的 RagFactory 测试夹具。
     *
     * @param string|null $basePath 向量文件目录，默认按 pid 隔离的临时路径
     */
    private function makeRagFactory(?string $basePath = null): RagFactory
    {
        $path = $basePath ?? (sys_get_temp_dir() . '/swoolefy_rag_module_' . getmypid());
        $config = NeuronAiConfig::fromArray([
            'rag' => [
                'default_vector_store' => NeuronAiVectorStoreName::FILE,
                'default_top_k' => 5,
                'embedding_model' => 'text-embedding-3-small',
                'embedding_dimension' => 1536,
                'allow_fake_embeddings' => true,
                'require_tenant_isolation' => false,
                'vector_stores' => [
                    NeuronAiVectorStoreName::FILE => ['path' => $path],
                ],
            ],
        ]);

        return new RagFactory(new VectorStoreFactory($config, $path), new EmbeddingFactory($config));
    }

    /**
     * 验证 VectorStoreFactory 在 FILE 默认配置下 storeType/alias 正确，make() 返回 FileVectorStore。
     */
    public function testVectorStoreFactoryFileMode(): void
    {
        $path = sys_get_temp_dir() . '/swoolefy_rag_vs_' . getmypid();
        $config = NeuronAiConfig::fromArray([
            'rag' => [
                'default_vector_store' => NeuronAiVectorStoreName::FILE,
                'default_top_k' => 4,
                'require_tenant_isolation' => false,
                'vector_stores' => [
                    NeuronAiVectorStoreName::FILE => ['path' => $path],
                ],
            ],
        ]);
        $factory = new VectorStoreFactory($config, $path);

        $this->assertTrue($factory->storeType() === NeuronAiVectorStoreName::FILE, 'store type file');
        $this->assertTrue($factory->storeAlias() === NeuronAiVectorStoreName::FILE, 'store alias file');
        $store = $factory->make('kb_demo');
        $this->assertTrue($store instanceof FileVectorStore, 'file vector store');
    }

    /**
     * 验证知识库名含 `../`、空格、特殊字符时 make() 仍能创建 FileVectorStore（内部净化路径）。
     */
    public function testVectorStoreSanitizesKnowledgeBaseName(): void
    {
        $path = sys_get_temp_dir() . '/swoolefy_rag_sanitize_' . getmypid();
        $config = NeuronAiConfig::fromArray([
            'rag' => [
                'default_vector_store' => NeuronAiVectorStoreName::FILE,
                'require_tenant_isolation' => false,
                'vector_stores' => [
                    NeuronAiVectorStoreName::FILE => ['path' => $path],
                ],
            ],
        ]);
        $factory = new VectorStoreFactory($config, $path);
        $store = $factory->make('../evil/kb name!');
        $this->assertTrue($store instanceof FileVectorStore, 'sanitized store created');
    }

    /**
     * 验证多 alias 配置下 defaultVectorStoreAlias、vectorStoreDriver、milvusUri、pgvectorComponent 等读取正确。
     *
     * 默认 alias 与显式 primary_file 均应产出 FileVectorStore。
     */
    public function testVectorStoreCustomAliasWithDriver(): void
    {
        $path = sys_get_temp_dir() . '/swoolefy_rag_alias_' . getmypid();
        $config = NeuronAiConfig::fromArray([
            'rag' => [
                'default_vector_store' => 'primary_file',
                'require_tenant_isolation' => false,
                'vector_stores' => [
                    'primary_file' => [
                        'driver' => NeuronAiVectorStoreName::FILE,
                        'path' => $path . '/primary',
                    ],
                    'milvus_prod' => [
                        'driver' => NeuronAiVectorStoreName::MILVUS,
                        'uri' => 'http://milvus.example:19530',
                        'user' => 'root',
                        'password' => 'secret',
                        'dimension' => 1024,
                    ],
                    'pgvector_prod' => [
                        'driver' => NeuronAiVectorStoreName::PGVECTOR,
                        'component' => 'pg',
                        'table_name' => 'rag_pg',
                        'dimension' => 768,
                        'metric' => PgVectorStore::METRIC_COSINE,
                    ],
                ],
            ],
        ]);

        $this->assertTrue($config->defaultVectorStoreAlias() === 'primary_file', 'default alias');
        $this->assertTrue($config->vectorStoreDriver() === NeuronAiVectorStoreName::FILE, 'default driver');
        $this->assertTrue($config->vectorStoreDriver('milvus_prod') === NeuronAiVectorStoreName::MILVUS, 'alias driver');
        $this->assertTrue($config->milvusUri('milvus_prod') === 'http://milvus.example:19530', 'alias uri');
        $this->assertTrue($config->vectorStoreDriver('pgvector_prod') === NeuronAiVectorStoreName::PGVECTOR, 'pgvector alias driver');
        $this->assertTrue($config->pgvectorComponent('pgvector_prod') === 'pg', 'pgvector component');

        $factory = new VectorStoreFactory($config);
        $store = $factory->make('kb1');
        $this->assertTrue($store instanceof FileVectorStore, 'default alias uses file');
        $store2 = $factory->make('kb1', storeAlias: 'primary_file');
        $this->assertTrue($store2 instanceof FileVectorStore, 'explicit alias file');
    }

    /**
     * 验证 ingestTexts 写入两条文档后，RetrievalService 能检索到相关内容且 hit 含 content/score。
     */
    public function testIngestTextsAndRetrieve(): void
    {
        $rag = $this->makeRagFactory();
        $kb = 'product_kb_' . getmypid();

        $result = $rag->ingestionPipeline()->ingestTexts($kb, [
            'Swoolefy is a PHP coroutine framework based on Swoole.',
            'RAG retrieves relevant documents for the LLM.',
        ]);

        $this->assertTrue($result->documentCount === 2, 'ingested 2 docs');
        $this->assertTrue($result->knowledgeBase === $kb, 'kb name');

        $hits = (new RetrievalService($rag))->retrieve($kb, 'coroutine framework', 3);
        $this->assertTrue(count($hits) >= 1, 'retrieval finds content');
        $this->assertTrue(isset($hits[0]['content'], $hits[0]['score']), 'hit shape');
    }

    /**
     * 验证 ingestTexts 对空串/纯空白文本跳过，documentCount 为 0。
     */
    public function testIngestEmptyReturnsZero(): void
    {
        $rag = $this->makeRagFactory();
        $result = $rag->ingestionPipeline()->ingestTexts('empty_kb', ['', '  ', "\n"]);
        $this->assertTrue($result->documentCount === 0, 'empty texts skipped');
    }

    /**
     * 验证 ingest() 直接接受 NeuronAI Document 对象列表并计数。
     */
    public function testIngestDocumentsDirectly(): void
    {
        $rag = $this->makeRagFactory();
        $kb = 'docs_kb_' . getmypid();
        $docs = [new Document('Neuron AI structured output'), new Document('Milvus vector search')];
        $result = $rag->ingestionPipeline()->ingest($kb, $docs);
        $this->assertTrue($result->documentCount === 2, 'document ingest count');
    }

    /**
     * 验证 RagIngestNode / RagRetrieveNode 在 require_tenant_isolation 下从 WorkflowState 读取 tenantId。
     *
     * 场景：state 含 tenantId、documents、question，ingest 后 retrieve 应命中同租户数据。
     */
    public function testRagNodesPassTenantIdFromState(): void
    {
        $path = sys_get_temp_dir() . '/swoolefy_rag_node_tenant_' . getmypid();
        $config = NeuronAiConfig::fromArray([
            'rag' => [
                'default_vector_store' => NeuronAiVectorStoreName::FILE,
                'allow_fake_embeddings' => true,
                'require_tenant_isolation' => true,
                'vector_stores' => [
                    NeuronAiVectorStoreName::FILE => ['path' => $path],
                ],
            ],
        ]);
        $rag = new RagFactory(new VectorStoreFactory($config, $path), new EmbeddingFactory($config));
        $compiled = WorkflowComponentFactory::compiler(WorkflowConfig::fromArray([]))->compile(
            WorkflowDefinition::create('rag_node_tenant', '1.0.0')
                ->addNode('noop', new ClosureNode('noop', static fn () => NodeExecutionResult::success())),
        );
        $ctx = new RunContext('run-rag-node-tenant', $compiled);
        $state = new WorkflowState([
            'tenantId' => 'tenant_node',
            'documents' => ['Tenant node yellow widget manual.'],
            'question' => 'yellow widget',
        ]);

        $ingest = new RagIngestNode('ingest', ['knowledgeBase' => 'node_kb'], $rag->ingestionPipeline());
        $ingestResult = $ingest->execute($ctx, $state);
        $this->assertTrue(($ingestResult->output['ingestedCount'] ?? 0) === 1, 'node ingest ok');

        $retrieve = new RagRetrieveNode('retrieve', ['knowledgeBase' => 'node_kb'], new RetrievalService($rag));
        $retrieveResult = $retrieve->execute($ctx, $state);
        $docs = $retrieveResult->output['retrievedDocs'] ?? [];
        $this->assertTrue(is_array($docs) && count($docs) >= 1, 'node retrieve ok with state tenant');
    }

    /**
     * 验证 MODE_QUEUE 下 dispatcher 返回 queued 状态，producer 收到 tenant/metadata，consumer 消费后可检索。
     */
    public function testRagIngestDispatcherQueueMode(): void
    {
        TestRagIngestProducer::$jobs = [];
        $path = sys_get_temp_dir() . '/swoolefy_rag_queue_' . getmypid();
        $config = NeuronAiConfig::fromArray([
            'rag' => [
                'default_vector_store' => NeuronAiVectorStoreName::FILE,
                'allow_fake_embeddings' => true,
                'require_tenant_isolation' => true,
                'ingestion' => [
                    'mode' => RagIngestDispatcher::MODE_QUEUE,
                    'queue' => [
                        'producer' => [
                            'class' => TestRagIngestProducer::class,
                            'method' => 'push',
                        ],
                        'consumer' => [
                            'class' => TestRagIngestConsumer::class,
                            'method' => 'handle',
                        ],
                    ],
                ],
                'vector_stores' => [
                    NeuronAiVectorStoreName::FILE => ['path' => $path],
                ],
            ],
        ]);
        $rag = new RagFactory(new VectorStoreFactory($config, $path), new EmbeddingFactory($config));
        $pipeline = $rag->ingestionPipeline();
        $dispatcher = RagIngestDispatcher::fromConfig($pipeline, $config);

        $result = $dispatcher->ingestTexts(
            'queue_kb',
            ['Queued RAG ingest document.'],
            tenantId: 'tenant_queue',
            metadata: ['traceId' => 'trace-queue-1'],
        );

        $this->assertTrue($result->status === 'queued', 'queued result');
        $this->assertTrue($result->jobId !== null, 'job id returned');
        $this->assertTrue(count(TestRagIngestProducer::$jobs) === 1, 'producer received job');
        $this->assertTrue((TestRagIngestProducer::$jobs[0]['tenantId'] ?? '') === 'tenant_queue', 'tenant in job');
        $this->assertTrue((TestRagIngestProducer::$jobs[0]['metadata']['traceId'] ?? '') === 'trace-queue-1', 'metadata in job');

        // 模拟从队列中取出 job 并由 ConfigurableRagIngestQueue 消费入库
        $job = RagIngestJob::fromArray(TestRagIngestProducer::$jobs[0]);
        $ingestion = (array) ($config->ragSection()['ingestion'] ?? []);
        $queueConfig = $ingestion['queue'] ?? [];
        $consumed = (new ConfigurableRagIngestQueue(is_array($queueConfig) ? $queueConfig : []))->consume($job, $pipeline);
        $this->assertTrue($consumed->documentCount === 1, 'consumer ingests one document');

        $hits = (new RetrievalService($rag))->retrieve('queue_kb', 'Queued RAG', 3, tenantId: 'tenant_queue');
        $this->assertTrue(count($hits) >= 1, 'queued document is retrievable after consume');
    }

    /**
     * 验证 RagFactory::retrieval() 构建 similarity 检索器，embeddings() 返回 EmbeddingsProviderInterface。
     */
    public function testRagFactoryRetrievalInterface(): void
    {
        $rag = $this->makeRagFactory();
        $retrieval = $rag->retrieval('any_kb', 2);
        $this->assertTrue($retrieval !== null, 'similarity retrieval built');
        $this->assertTrue($rag->embeddings() instanceof \NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface, 'embeddings');
    }

    /**
     * 验证 MilvusVectorStore::make() 参数装配与 getCollectionName()（无网络调用）。
     */
    public function testMilvusVectorStoreMakeParams(): void
    {
        $store = MilvusVectorStore::make([
            'uri' => 'http://127.0.0.1:19530',
            'user' => 'root',
            'password' => 'pass',
            'db_name' => 'default',
            'collection_name' => 'kb_test',
            'dimension' => 1536,
            'top_k' => 3,
            'auto_create_collection' => false,
        ]);

        $this->assertTrue($store instanceof MilvusVectorStore, 'milvus store');
        $this->assertTrue($store->getCollectionName() === 'kb_test', 'collection name');
    }

    /**
     * 验证 sanitizeCollectionName：连字符转下划线、数字开头加 c_ 前缀、合法名不变；make() 自动净化。
     */
    public function testMilvusSanitizeCollectionName(): void
    {
        $this->assertTrue(
            MilvusVectorStore::sanitizeCollectionName('tenant-a_product-kb') === 'tenant_a_product_kb',
            'hyphen replaced with underscore',
        );
        $this->assertTrue(
            MilvusVectorStore::sanitizeCollectionName('123kb') === 'c_123kb',
            'leading digit gets c_ prefix',
        );
        $this->assertTrue(
            MilvusVectorStore::sanitizeCollectionName('ok_name') === 'ok_name',
            'valid name unchanged',
        );

        $store = MilvusVectorStore::make([
            'uri' => 'http://127.0.0.1:19530',
            'collection_name' => 'acme-corp/kb-v1',
            'auto_create_collection' => false,
        ]);
        $this->assertTrue($store->getCollectionName() === 'acme_corp_kb_v1', 'make() sanitizes collection name');
    }

    /**
     * 验证 MilvusFilterExpr::deleteBySourceFilter 对引号转义、仅 sourceType、控制字符拒绝。
     */
    public function testMilvusFilterExprEscapesQuotes(): void
    {
        $filter = MilvusFilterExpr::deleteBySourceFilter('file', 'readme "draft".md');
        $this->assertTrue(
            $filter === 'metadata["sourceType"] == "file" and metadata["sourceName"] == "readme \\"draft\\".md"',
            'quotes escaped in filter',
        );

        $onlyType = MilvusFilterExpr::deleteBySourceFilter('manual');
        $this->assertTrue($onlyType === 'metadata["sourceType"] == "manual"', 'sourceType only filter');

        try {
            MilvusFilterExpr::deleteBySourceFilter('file', "bad\nline");
            $this->assertTrue(false, 'control char should throw');
        } catch (\InvalidArgumentException $e) {
            $this->assertTrue(str_contains($e->getMessage(), 'control characters'), 'control char message');
        }
    }

    /**
     * 验证 PgVectorStore::make() 参数与 embeddingLiteral() 向量字面量格式。
     */
    public function testPgVectorStoreMakeParams(): void
    {
        $store = PgVectorStore::make([
            'pdo' => new PDO('sqlite::memory:'),
            'table_name' => 'rag_pg_test',
            'dimension' => 3,
            'top_k' => 2,
            'metric' => PgVectorStore::METRIC_COSINE,
        ]);

        $this->assertTrue($store instanceof PgVectorStore, 'pgvector store');
        $this->assertTrue($store->getTableName() === 'rag_pg_test', 'pgvector table name');
        $this->assertTrue(PgVectorStore::embeddingLiteral([0.1, 2, -3.5]) === '[0.1,2,-3.5]', 'pgvector vector literal');
    }

    /**
     * 验证非法 table_name 与未知 metric 时 PgVectorStore::make() 抛 RuntimeException。
     */
    public function testPgVectorStoreRejectsInvalidConfig(): void
    {
        try {
            PgVectorStore::make([
                'pdo' => new PDO('sqlite::memory:'),
                'table_name' => 'bad-name',
            ]);
            $this->assertTrue(false, 'invalid table name should throw');
        } catch (RuntimeException $e) {
            $this->assertTrue(str_contains($e->getMessage(), 'Invalid PostgreSQL identifier'), 'invalid identifier message');
        }

        try {
            PgVectorStore::make([
                'pdo' => new PDO('sqlite::memory:'),
                'table_name' => 'rag_pg_test',
                'metric' => 'unknown',
            ]);
            $this->assertTrue(false, 'invalid metric should throw');
        } catch (RuntimeException $e) {
            $this->assertTrue(str_contains($e->getMessage(), 'Unsupported pgvector metric'), 'invalid metric message');
        }
    }

    /**
     * 验证显式传入不存在的 storeAlias 时 make() 抛 Unknown vector store alias。
     */
    public function testVectorStoreUnknownAliasThrows(): void
    {
        $path = sys_get_temp_dir() . '/swoolefy_rag_unknown_' . getmypid();
        $config = NeuronAiConfig::fromArray([
            'rag' => [
                'default_vector_store' => NeuronAiVectorStoreName::FILE,
                'vector_stores' => [
                    NeuronAiVectorStoreName::FILE => ['path' => $path],
                ],
            ],
        ]);
        $factory = new VectorStoreFactory($config, $path);

        try {
            $factory->make('kb', storeAlias: 'missing_alias');
            $this->assertTrue(false, 'should throw');
        } catch (RuntimeException $e) {
            $this->assertTrue(str_contains($e->getMessage(), 'Unknown vector store alias'), 'unknown alias message');
        }
    }

    /**
     * 验证 default_vector_store 指向未声明 alias 时 make() 抛错。
     */
    public function testVectorStoreUnknownDefaultAliasThrows(): void
    {
        $config = NeuronAiConfig::fromArray([
            'rag' => [
                'default_vector_store' => 'not_declared',
                'vector_stores' => [],
            ],
        ]);
        $factory = new VectorStoreFactory($config);

        try {
            $factory->make('kb');
            $this->assertTrue(false, 'should throw');
        } catch (RuntimeException $e) {
            $this->assertTrue(str_contains($e->getMessage(), 'Unknown vector store alias'), 'default alias message');
        }
    }

    /**
     * 验证 vector_stores 中 driver 拼写错误（如 milvu）时抛 Unknown vector store driver。
     */
    public function testVectorStoreUnknownDriverThrows(): void
    {
        $path = sys_get_temp_dir() . '/swoolefy_rag_bad_driver_' . getmypid();
        $config = NeuronAiConfig::fromArray([
            'rag' => [
                'default_vector_store' => 'typo_store',
                'require_tenant_isolation' => false,
                'vector_stores' => [
                    'typo_store' => [
                        'driver' => 'milvu',
                        'path' => $path,
                    ],
                ],
            ],
        ]);
        $factory = new VectorStoreFactory($config, $path);

        try {
            $factory->make('kb');
            $this->assertTrue(false, 'should throw on unknown driver');
        } catch (RuntimeException $e) {
            $this->assertTrue(str_contains($e->getMessage(), 'Unknown vector store driver'), 'unknown driver message');
            $this->assertTrue(str_contains($e->getMessage(), 'milvu'), 'includes bad driver name');
        }
    }

    /**
     * 验证 NeuronAiConfig 对 Milvus 默认 alias、driver、uri/user/password/db/dimension 的读取。
     */
    public function testMilvusConfigSectionInNeuronAiConfig(): void
    {
        $config = NeuronAiConfig::fromArray([
            'rag' => [
                'default_vector_store' => NeuronAiVectorStoreName::MILVUS,
                'vector_stores' => [
                    NeuronAiVectorStoreName::MILVUS => [
                        'uri' => 'http://c-demo.milvus.aliyuncs.com:19530',
                        'user' => 'root',
                        'password' => 'secret',
                        'db_name' => 'rag',
                        'dimension' => 1024,
                    ],
                ],
            ],
        ]);

        $this->assertTrue($config->defaultVectorStoreAlias() === NeuronAiVectorStoreName::MILVUS, 'alias');
        $this->assertTrue($config->vectorStoreDriver() === NeuronAiVectorStoreName::MILVUS, 'driver');
        $this->assertTrue($config->milvusUri() === 'http://c-demo.milvus.aliyuncs.com:19530', 'uri');
        $this->assertTrue($config->milvusUser() === 'root', 'user');
        $this->assertTrue($config->milvusPassword() === 'secret', 'password');
        $this->assertTrue($config->milvusDbName() === 'rag', 'db');
        $this->assertTrue($config->milvusDimension() === 1024, 'dimension');
    }

    /**
     * 验证 NeuronAiConfig 对 PgVector alias、driver、component、table、dimension、metric 的读取。
     */
    public function testPgVectorConfigSectionInNeuronAiConfig(): void
    {
        $config = NeuronAiConfig::fromArray([
            'rag' => [
                'default_vector_store' => NeuronAiVectorStoreName::PGVECTOR,
                'vector_stores' => [
                    NeuronAiVectorStoreName::PGVECTOR => [
                        'component' => 'pg',
                        'table_name' => 'rag_pg_documents',
                        'dimension' => 768,
                        'metric' => PgVectorStore::METRIC_L2,
                    ],
                ],
            ],
        ]);

        $this->assertTrue($config->defaultVectorStoreAlias() === NeuronAiVectorStoreName::PGVECTOR, 'pgvector alias');
        $this->assertTrue($config->vectorStoreDriver() === NeuronAiVectorStoreName::PGVECTOR, 'pgvector driver');
        $this->assertTrue($config->pgvectorComponent() === 'pg', 'pgvector component');
        $this->assertTrue($config->pgvectorTableName() === 'rag_pg_documents', 'pgvector table');
        $this->assertTrue($config->pgvectorDimension() === 768, 'pgvector dimension');
        $this->assertTrue($config->pgvectorMetric() === PgVectorStore::METRIC_L2, 'pgvector metric');
    }
}

/**
 * 队列模式测试用 Producer：将 RagIngestJob 序列化后存入静态 $jobs 供断言。
 */
final class TestRagIngestProducer
{
    /** @var list<array<string, mixed>> */
    public static array $jobs = [];

    public function push(RagIngestJob $job): void
    {
        self::$jobs[] = $job->toArray();
    }
}

/**
 * 队列模式测试用 Consumer：直接调用 IngestionPipeline::ingestTexts 完成入库。
 */
final class TestRagIngestConsumer
{
    public function handle(RagIngestJob $job, IngestionPipeline $pipeline): IngestResult
    {
        return $pipeline->ingestTexts(
            $job->knowledgeBase,
            $job->texts,
            storeAlias: $job->vectorStore,
            tenantId: $job->tenantId,
        );
    }
}

