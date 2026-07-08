<?php

declare(strict_types=1);

namespace Swoolefy\Support\Rag\Factory;

use NeuronAI\RAG\VectorStore\FileVectorStore;
use NeuronAI\RAG\VectorStore\MariaDBVectorStore;
use NeuronAI\RAG\VectorStore\MeilisearchVectorStore;
use NeuronAI\RAG\VectorStore\PineconeVectorStore;
use NeuronAI\RAG\VectorStore\QdrantVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use RuntimeException;
use Swoolefy\Support\Neuron\Http\NeuronHttpFactory;
use Swoolefy\Support\TenantScope;
use Swoolefy\Support\Neuron\NeuronAiConfig;
use Swoolefy\Support\Neuron\NeuronAiVectorStoreName;
use Swoolefy\Support\Rag\Resolver\RagPdoResolver;
use Swoolefy\Support\Rag\Store\MeilisearchConfig;
use Swoolefy\Support\Rag\Store\MilvusVectorStore;
use Swoolefy\Support\Rag\Store\PgVectorStore;

/**
 * 向量存储工厂 —— 按别名解析 rag.vector_stores 配置，按 knowledgeBase 隔离索引/目录/表。
 *
 * 配置约定（neuron_ai.php）：
 *   rag.default_vector_store   — 默认别名
 *   rag.vector_stores[alias]   — 连接参数；可选 driver，缺省时别名即驱动名
 *
 * 业务指定别名：
 *   $factory->make('product_kb', storeAlias: 'milvus_prod');
 *   null 时使用 default_vector_store。
 *
 * 支持驱动：file、meilisearch、phpvector、mariadb、pgvector、pinecone、qdrant、milvus
 *
 * @see docs/swoolefyAI.md §4.10.2
 */
final class VectorStoreFactory
{
    public function __construct(
        private readonly NeuronAiConfig $config,
        private readonly ?string $basePathOverride = null,
    ) {
    }

    public static function fromEnv(?string $basePath = null): self
    {
        return new self(NeuronAiConfig::load(), $basePath);
    }

    /**
     * 按别名创建 VectorStore 实例。
     *
     * Phase A：未知 alias 直接抛 RuntimeException（fail-fast），
     * 须在 rag.vector_stores 中显式声明，避免静默回退到错误驱动。
     *
     * @param string      $knowledgeBase 知识库名（映射为 index / 目录 / collection）
     * @param int|null    $topK          检索 TopK，null 用配置 default_top_k
     * @param string|null $storeAlias    向量库别名；null 使用 default_vector_store
     * @param string|null $tenantId      租户 ID；null 时从 FrameworkContext 读取
     *
     * @throws RuntimeException 别名未声明或 require_tenant_isolation 下 tenant 为空
     */
    public function make(
        string $knowledgeBase,
        ?int $topK = null,
        ?string $storeAlias = null,
        ?string $tenantId = null,
    ): VectorStoreInterface {
        $alias = $storeAlias ?? $this->config->defaultVectorStoreAlias();
        if (!$this->config->hasVectorStoreAlias($alias)) {
            throw new RuntimeException(
                "Unknown vector store alias [{$alias}]; declare it under rag.vector_stores in neuron_ai.php",
            );
        }

        $driver = $this->config->vectorStoreDriver($alias);
        $index = TenantScope::scopedKnowledgeBase(
            $knowledgeBase,
            $tenantId,
            $this->config->requireTenantIsolation(),
        );
        $k = $topK ?? $this->config->defaultTopK();

        return match ($driver) {
            NeuronAiVectorStoreName::MEILISEARCH => $this->makeMeilisearch($index, $k, $alias),
            NeuronAiVectorStoreName::PHP_VECTOR => $this->makePhpVector($index, $k, $alias),
            NeuronAiVectorStoreName::MARIADB => $this->makeMariaDb($index, $k, $alias),
            NeuronAiVectorStoreName::PGVECTOR => $this->makePgVector($index, $k, $alias),
            NeuronAiVectorStoreName::PINECONE => $this->makePinecone($index, $k, $alias),
            NeuronAiVectorStoreName::QDRANT => $this->makeQdrant($index, $k, $alias),
            NeuronAiVectorStoreName::MILVUS => $this->makeMilvus($index, $k, $alias),
            default => $this->makeFile($index, $k, $alias),
        };
    }

    /** 当前默认别名（非驱动名；自定义别名时二者可能不同）。 */
    public function storeAlias(): string
    {
        return $this->config->defaultVectorStoreAlias();
    }

    /** 当前默认别名解析出的驱动类型。 */
    public function storeType(): string
    {
        return $this->config->vectorStoreDriver();
    }

    private function makeFile(string $index, int $topK, string $alias): FileVectorStore
    {
        $basePath = $this->basePathOverride ?? $this->config->fileStorePath($alias);
        $directory = rtrim($basePath, '/') . '/' . $index;

        return new FileVectorStore(
            directory: $directory,
            topK: $topK,
            name: $index,
        );
    }

    private function makeMeilisearch(string $index, int $topK, string $alias): MeilisearchVectorStore
    {
        $meilisearch = MeilisearchConfig::fromNeuronAiConfig($this->config, $alias);

        return new MeilisearchVectorStore(
            indexUid: $index,
            host: $meilisearch->host,
            key: $meilisearch->apiKey,
            embedder: $meilisearch->embedder,
            topK: $topK,
            dimension: $meilisearch->dimension,
        );
    }

    private function makePhpVector(string $index, int $topK, string $alias): VectorStoreInterface
    {
        if (!class_exists(\NeuronAI\PHPVector\PHPVector::class)) {
            throw new RuntimeException(
                'PHPVector requires composer package neuron-core/php-vector. Run: composer require neuron-core/php-vector',
            );
        }

        $path = rtrim($this->config->phpvectorPath($alias), '/') . '/' . $index;

        return new \NeuronAI\PHPVector\PHPVector(
            path: $path,
            topK: $topK,
        );
    }

    private function makeMariaDb(string $index, int $topK, string $alias): MariaDBVectorStore
    {
        $tableName = $this->config->mariadbTableName($alias) . '_' . $index;
        $pdo = RagPdoResolver::resolve($this->config->mariadbComponent($alias));

        return new MariaDBVectorStore(
            pdo: $pdo,
            tableName: $tableName,
            topK: $topK,
        );
    }

    /**
     * PostgreSQL + pgvector：each knowledgeBase is an isolated table ({table_name}_{$index}).
     */
    private function makePgVector(string $index, int $topK, string $alias): PgVectorStore
    {
        $tableName = $this->config->pgvectorTableName($alias) . '_' . $index;
        $pdo = RagPdoResolver::resolve($this->config->pgvectorComponent($alias));

        return PgVectorStore::make([
            'pdo' => $pdo,
            'table_name' => $tableName,
            'dimension' => $this->config->pgvectorDimension($alias),
            'top_k' => $topK,
            'metric' => $this->config->pgvectorMetric($alias),
        ]);
    }

    private function makePinecone(string $index, int $topK, string $alias): PineconeVectorStore
    {
        $key = $this->config->pineconeKey($alias);
        $indexUrl = $this->config->pineconeIndexUrl($alias);
        if ($key === '' || $indexUrl === '') {
            throw new RuntimeException(
                "Pinecone vector store [{$alias}] requires key and index_url.",
            );
        }

        return new PineconeVectorStore(
            key: $key,
            indexUrl: $indexUrl,
            topK: $topK,
            version: $this->config->pineconeVersion($alias),
            namespace: $index,
            httpClient: NeuronHttpFactory::create(),
        );
    }

    private function makeQdrant(string $index, int $topK, string $alias): QdrantVectorStore
    {
        $collectionUrl = rtrim($this->config->qdrantBaseUrl($alias), '/')
            . '/collections/' . $index . '/';

        return new QdrantVectorStore(
            collectionUrl: $collectionUrl,
            key: $this->config->qdrantKey($alias),
            topK: $topK,
            dimension: $this->config->qdrantDimension($alias),
            httpClient: NeuronHttpFactory::create(),
        );
    }

    /**
     * Aliyun / self-hosted Milvus: each knowledgeBase is an isolated collection ($index).
     */
    private function makeMilvus(string $index, int $topK, string $alias): MilvusVectorStore
    {
        return MilvusVectorStore::make([
            'uri' => $this->config->milvusUri($alias),
            'user' => $this->config->milvusUser($alias),
            'password' => $this->config->milvusPassword($alias),
            'token' => $this->config->milvusToken($alias),
            'db_name' => $this->config->milvusDbName($alias),
            'collection_name' => $index,
            'dimension' => $this->config->milvusDimension($alias),
            'top_k' => $topK,
        ]);
    }

}
