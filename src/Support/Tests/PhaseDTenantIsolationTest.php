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

/**
 * Phase D —— 多租户 RAG / ChatHistory 隔离测试。
 *
 * 运行：composer test:phase-d
 */

use NeuronAI\RAG\VectorStore\FileVectorStore;
use Swoolefy\Support\Neuron\Embedding\EmbeddingFactory;
use Swoolefy\Support\Neuron\NeuronAiConfig;
use Swoolefy\Support\Neuron\NeuronAiVectorStoreName;
use Swoolefy\Support\ProductionHealthCheck;
use Swoolefy\Support\Rag\Factory\RagFactory;
use Swoolefy\Support\Rag\Factory\VectorStoreFactory;
use Swoolefy\Support\Rag\Retrieval\RetrievalService;
use Swoolefy\Support\TenantScope;
use Swoolefy\Support\Workflow\WorkflowConfig;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function pass(string $name): void
{
    echo "[PASS] {$name}\n";
}

function fileStoreDirectory(FileVectorStore $store): string
{
    $ref = new ReflectionClass($store);
    $prop = $ref->getProperty('directory');
    $prop->setAccessible(true);

    return (string) $prop->getValue($store);
}

function testScopedKnowledgeBaseSeparatesTenants(): void
{
    assertTrue(
        TenantScope::scopedKnowledgeBase('docs', 'tenant_a', false) === 'tenant_a_docs',
        'tenant_a docs',
    );
    assertTrue(
        TenantScope::scopedKnowledgeBase('docs', 'tenant_b', false) === 'tenant_b_docs',
        'tenant_b docs',
    );
    assertTrue(
        TenantScope::scopedKnowledgeBase('docs', 'tenant_a', false)
            !== TenantScope::scopedKnowledgeBase('docs', 'tenant_b', false),
        'different tenants differ',
    );

    pass('scoped knowledge base separates tenants');
}

function testVectorStoreFactoryUsesTenantPrefix(): void
{
    $path = sys_get_temp_dir() . '/swoolefy_tenant_vs_' . getmypid();
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

    $storeA = $factory->make('product_kb', tenantId: 't1');
    $storeB = $factory->make('product_kb', tenantId: 't2');
    assertTrue($storeA instanceof FileVectorStore, 'store a');
    assertTrue($storeB instanceof FileVectorStore, 'store b');
    assertTrue(
        fileStoreDirectory($storeA) !== fileStoreDirectory($storeB),
        'same kb name different tenant dirs',
    );
    assertTrue(str_ends_with(fileStoreDirectory($storeA), '/t1_product_kb'), 't1 path suffix');
    assertTrue(str_ends_with(fileStoreDirectory($storeB), '/t2_product_kb'), 't2 path suffix');

    pass('vector store factory uses tenant prefix');
}

function testRequireTenantIsolationThrowsWithoutTenant(): void
{
    $path = sys_get_temp_dir() . '/swoolefy_tenant_req_' . getmypid();
    $config = NeuronAiConfig::fromArray([
        'rag' => [
            'default_vector_store' => NeuronAiVectorStoreName::FILE,
            'require_tenant_isolation' => true,
            'vector_stores' => [
                NeuronAiVectorStoreName::FILE => ['path' => $path],
            ],
        ],
    ]);
    $factory = new VectorStoreFactory($config, $path);

    try {
        $factory->make('product_kb');
        assertTrue(false, 'should throw without tenant');
    } catch (RuntimeException $e) {
        assertTrue(str_contains($e->getMessage(), 'tenantId'), 'tenant required message');
    }

    try {
        TenantScope::redisChatKeyPrefix(null, true);
        assertTrue(false, 'redis prefix should throw without tenant');
    } catch (RuntimeException $e) {
        assertTrue(str_contains($e->getMessage(), 'tenantId'), 'redis tenant required');
    }

    pass('require tenant isolation throws without tenant');
}

function testRedisChatKeyFormat(): void
{
    assertTrue(
        TenantScope::redisChatKeyPrefix('acme', false) === 'chat:acme:thread:',
        'prefix format',
    );
    assertTrue(
        TenantScope::redisChatKey('thread-42', 'acme', false) === 'chat:acme:thread:thread-42',
        'full key format',
    );

    pass('redis chat key format');
}

function testProductionHealthCheckRequiresTenantIsolation(): void
{
    $errors = ProductionHealthCheck::check(
        NeuronAiConfig::fromArray([
            'rag' => [
                'default_vector_store' => NeuronAiVectorStoreName::FILE,
                'allow_fake_embeddings' => false,
                'require_tenant_isolation' => false,
                'embedding_dimension' => 1536,
                'vector_stores' => [
                    NeuronAiVectorStoreName::FILE => ['path' => '/tmp/swoolefy_rag_health'],
                ],
            ],
            'neuron' => ['ai_model_providers' => []],
        ]),
        WorkflowConfig::fromArray([
            'workflow' => [
                'default_node_timeout_seconds' => 60,
                'default_run_store' => 'memory',
                'run_stores' => ['memory' => []],
            ],
        ]),
    );

    $found = false;
    foreach ($errors as $error) {
        if (str_contains($error, 'require_tenant_isolation')) {
            $found = true;
            break;
        }
    }
    assertTrue($found, 'health check flags missing tenant isolation in production');

    pass('production health check requires tenant isolation');
}

function testRagRetrievalServiceUsesExplicitTenantId(): void
{
    $path = sys_get_temp_dir() . '/swoolefy_tenant_retrieval_' . getmypid();
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
    $kb = 'tenant_kb_' . getmypid();

    $rag->ingestionPipeline()->ingestTexts($kb, ['Tenant A blue widget manual.'], tenantId: 'tenant_a');
    $hits = (new RetrievalService($rag))->retrieve($kb, 'blue widget', 3, tenantId: 'tenant_a');
    assertTrue(count($hits) >= 1, 'explicit tenant retrieval works');

    try {
        (new RetrievalService($rag))->retrieve($kb, 'blue widget', 3);
        assertTrue(false, 'missing tenant should throw');
    } catch (RuntimeException $e) {
        assertTrue(str_contains($e->getMessage(), 'tenantId'), 'missing tenant fails fast');
    }

    pass('rag retrieval service uses explicit tenant id');
}

$tests = [
    'scoped knowledge base' => 'testScopedKnowledgeBaseSeparatesTenants',
    'vector store tenant prefix' => 'testVectorStoreFactoryUsesTenantPrefix',
    'require tenant throws' => 'testRequireTenantIsolationThrowsWithoutTenant',
    'redis chat key format' => 'testRedisChatKeyFormat',
    'health check tenant isolation' => 'testProductionHealthCheckRequiresTenantIsolation',
    'rag retrieval explicit tenant' => 'testRagRetrievalServiceUsesExplicitTenantId',
];

$passed = 0;
foreach ($tests as $name => $fn) {
    $fn();
    $passed++;
}

echo "\nRag tenant isolation: {$passed}/" . count($tests) . " passed.\n";
