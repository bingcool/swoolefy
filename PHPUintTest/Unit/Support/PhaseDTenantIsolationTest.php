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

namespace PHPUintTest\Unit\Support;

/**
 * Phase D —— 多租户 RAG / ChatHistory 隔离测试。
 *
 * ## 覆盖范围
 * | 区域 | 要点 |
 * |------|------|
 * | TenantScope | knowledgeBase 前缀、Redis chat key 格式 |
 * | VectorStoreFactory | 同 kb 名不同 tenant → 不同 FileVectorStore 目录 |
 * | require_tenant_isolation | 缺 tenantId 时 factory / redis prefix fail-fast |
 * | ProductionHealthCheck | 生产建议开启 require_tenant_isolation |
 * | RetrievalService | 显式 tenantId 检索；缺租户抛错 |
 *
 * 说明：File 向量库存于系统临时目录；ingestion 使用 allow_fake_embeddings，不打外网 Embedding API。
 */

use NeuronAI\RAG\VectorStore\FileVectorStore;
use ReflectionClass;
use RuntimeException;
use Swoolefy\Support\Neuron\Embedding\EmbeddingFactory;
use Swoolefy\Support\Neuron\NeuronAiConfig;
use Swoolefy\Support\Neuron\NeuronAiVectorStoreName;
use Swoolefy\Support\ProductionHealthCheck;
use Swoolefy\Support\Rag\Factory\RagFactory;
use Swoolefy\Support\Rag\Factory\VectorStoreFactory;
use Swoolefy\Support\Rag\Retrieval\RetrievalService;
use Swoolefy\Support\TenantScope;
use Swoolefy\Support\Workflow\WorkflowConfig;
use PHPUintTest\TestCase;

final class PhaseDTenantIsolationTest extends TestCase
{
    /**
     * 通过反射读取 FileVectorStore 内部 directory，用于断言租户目录隔离。
     * （NeuronAI FileVectorStore 未暴露 public getter。）
     */
    private function fileStoreDirectory(FileVectorStore $store): string
    {
        $ref = new ReflectionClass($store);
        $prop = $ref->getProperty('directory');
        $prop->setAccessible(true);

        return (string) $prop->getValue($store);
    }

    /**
     * scopedKnowledgeBase 按 `{tenantId}_{kb}` 拼接，保证不同租户逻辑库名不同。
     */
    public function testScopedKnowledgeBaseSeparatesTenants(): void
    {
        $this->assertTrue(
            TenantScope::scopedKnowledgeBase('docs', 'tenant_a', false) === 'tenant_a_docs',
            'tenant_a docs',
        );
        $this->assertTrue(
            TenantScope::scopedKnowledgeBase('docs', 'tenant_b', false) === 'tenant_b_docs',
            'tenant_b docs',
        );
        $this->assertTrue(
            TenantScope::scopedKnowledgeBase('docs', 'tenant_a', false)
                !== TenantScope::scopedKnowledgeBase('docs', 'tenant_b', false),
            'different tenants differ',
        );
    }

    /**
     * VectorStoreFactory.make 在传入 tenantId 时，File store 目录应以 `/{tenant}_{kb}` 结尾，
     * 且 t1 / t2 目录路径不同。
     */
    public function testVectorStoreFactoryUsesTenantPrefix(): void
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
        $this->assertTrue($storeA instanceof FileVectorStore, 'store a');
        $this->assertTrue($storeB instanceof FileVectorStore, 'store b');
        $this->assertTrue(
            $this->fileStoreDirectory($storeA) !== $this->fileStoreDirectory($storeB),
            'same kb name different tenant dirs',
        );
        $this->assertTrue(str_ends_with($this->fileStoreDirectory($storeA), '/t1_product_kb'), 't1 path suffix');
        $this->assertTrue(str_ends_with($this->fileStoreDirectory($storeB), '/t2_product_kb'), 't2 path suffix');
    }

    /**
     * require_tenant_isolation=true 时：
     * - VectorStoreFactory.make 无 tenantId → RuntimeException
     * - TenantScope::redisChatKeyPrefix(null, true) → RuntimeException
     */
    public function testRequireTenantIsolationThrowsWithoutTenant(): void
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
            $this->assertTrue(false, 'should throw without tenant');
        } catch (RuntimeException $e) {
            $this->assertTrue(str_contains($e->getMessage(), 'tenantId'), 'tenant required message');
        }

        try {
            TenantScope::redisChatKeyPrefix(null, true);
            $this->assertTrue(false, 'redis prefix should throw without tenant');
        } catch (RuntimeException $e) {
            $this->assertTrue(str_contains($e->getMessage(), 'tenantId'), 'redis tenant required');
        }
    }

    /**
     * Redis ChatHistory key 约定：`chat:{tenant}:thread:` / `chat:{tenant}:thread:{id}`。
     */
    public function testRedisChatKeyFormat(): void
    {
        $this->assertTrue(
            TenantScope::redisChatKeyPrefix('acme', false) === 'chat:acme:thread:',
            'prefix format',
        );
        $this->assertTrue(
            TenantScope::redisChatKey('thread-42', 'acme', false) === 'chat:acme:thread:thread-42',
            'full key format',
        );
    }

    /**
     * HealthCheck 在 require_tenant_isolation=false 时应给出提示项（生产多租户场景防串库）。
     */
    public function testProductionHealthCheckRequiresTenantIsolation(): void
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
        $this->assertTrue($found, 'health check flags missing tenant isolation in production');
    }

    /**
     * RetrievalService：带 tenantId 可 ingest+retrieve；缺 tenantId 在 require 开启时 fail-fast。
     */
    public function testRagRetrievalServiceUsesExplicitTenantId(): void
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
        $this->assertTrue(count($hits) >= 1, 'explicit tenant retrieval works');

        try {
            (new RetrievalService($rag))->retrieve($kb, 'blue widget', 3);
            $this->assertTrue(false, 'missing tenant should throw');
        } catch (RuntimeException $e) {
            $this->assertTrue(str_contains($e->getMessage(), 'tenantId'), 'missing tenant fails fast');
        }
    }
}
