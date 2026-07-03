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
use Swoolefy\Support\Neuron\NeuronAiConfig;
use Swoolefy\Support\Neuron\NeuronAiVectorStoreName;
use Swoolefy\Support\Rag\Resolver\RagPdoResolver;
use Swoolefy\Support\Rag\Store\MeilisearchConfig;

/**
 * 向量存储工厂 —— 按配置切换多种 VectorStore，按 knowledgeBase 隔离索引/目录/表。
 *
 * 支持：file、meilisearch、phpvector、mariadb、pinecone、qdrant
 *
 * @see swoolefyAI.md §4.10.2
 */
final class VectorStoreFactory
{
    public function __construct(
        private readonly NeuronAiConfig $config,
        private readonly ?string $basePathOverride = null,
        private readonly ?MeilisearchConfig $meilisearch = null,
    ) {
    }

    public static function fromEnv(?string $basePath = null): self
    {
        $config = NeuronAiConfig::load();
        $storeType = $config->vectorStoreDriver();
        $meilisearch = $storeType === NeuronAiVectorStoreName::MEILISEARCH
            ? MeilisearchConfig::fromNeuronAiConfig($config)
            : null;

        return new self($config, $basePath, $meilisearch);
    }

    public function make(string $knowledgeBase, ?int $topK = null): VectorStoreInterface
    {
        $index = $this->sanitize($knowledgeBase);
        $k = $topK ?? $this->config->defaultTopK();

        return match ($this->config->vectorStoreDriver()) {
            NeuronAiVectorStoreName::MEILISEARCH => $this->makeMeilisearch($index, $k),
            NeuronAiVectorStoreName::PHP_VECTOR => $this->makePhpVector($index, $k),
            NeuronAiVectorStoreName::MARIADB => $this->makeMariaDb($index, $k),
            NeuronAiVectorStoreName::PINECONE => $this->makePinecone($index, $k),
            NeuronAiVectorStoreName::QDRANT => $this->makeQdrant($index, $k),
            default => $this->makeFile($index, $k),
        };
    }

    public function storeType(): string
    {
        return $this->config->vectorStoreDriver();
    }

    private function makeFile(string $index, int $topK): FileVectorStore
    {
        $basePath = $this->basePathOverride ?? $this->config->fileStorePath();
        $directory = rtrim($basePath, '/') . '/' . $index;

        return new FileVectorStore(
            directory: $directory,
            topK: $topK,
            name: $index,
        );
    }

    private function makeMeilisearch(string $index, int $topK): MeilisearchVectorStore
    {
        if ($this->meilisearch === null) {
            throw new RuntimeException('Meilisearch config is required when vector_store=meilisearch.');
        }

        return new MeilisearchVectorStore(
            indexUid: $index,
            host: $this->meilisearch->host,
            key: $this->meilisearch->apiKey,
            embedder: $this->meilisearch->embedder,
            topK: $topK,
            dimension: $this->meilisearch->dimension,
        );
    }

    private function makePhpVector(string $index, int $topK): VectorStoreInterface
    {
        if (!class_exists(\NeuronAI\PHPVector\PHPVector::class)) {
            throw new RuntimeException(
                'PHPVector requires composer package neuron-core/php-vector. Run: composer require neuron-core/php-vector',
            );
        }

        $path = rtrim($this->config->phpvectorPath(), '/') . '/' . $index;

        return new \NeuronAI\PHPVector\PHPVector(
            path: $path,
            topK: $topK,
        );
    }

    private function makeMariaDb(string $index, int $topK): MariaDBVectorStore
    {
        $tableName = $this->config->mariadbTableName() . '_' . $index;
        $pdo = RagPdoResolver::resolve($this->config->mariadbComponent());

        return new MariaDBVectorStore(
            pdo: $pdo,
            tableName: $tableName,
            topK: $topK,
        );
    }

    private function makePinecone(string $index, int $topK): PineconeVectorStore
    {
        $key = $this->config->pineconeKey();
        $indexUrl = $this->config->pineconeIndexUrl();
        if ($key === '' || $indexUrl === '') {
            throw new RuntimeException('Pinecone vector store requires pinecone.key and pinecone.index_url.');
        }

        return new PineconeVectorStore(
            key: $key,
            indexUrl: $indexUrl,
            topK: $topK,
            version: $this->config->pineconeVersion(),
            namespace: $index,
            httpClient: NeuronHttpFactory::create(),
        );
    }

    private function makeQdrant(string $index, int $topK): QdrantVectorStore
    {
        $collectionUrl = rtrim($this->config->qdrantBaseUrl(), '/')
            . '/collections/' . $index . '/';

        return new QdrantVectorStore(
            collectionUrl: $collectionUrl,
            key: $this->config->qdrantKey(),
            topK: $topK,
            dimension: $this->config->qdrantDimension(),
            httpClient: NeuronHttpFactory::create(),
        );
    }

    private function sanitize(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name) ?: 'default';
    }
}
