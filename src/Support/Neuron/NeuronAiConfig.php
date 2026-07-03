<?php

declare(strict_types=1);

namespace Swoolefy\Support\Neuron;

use Swoolefy\Support\ApplicationConfig;
use Swoolefy\Support\Neuron\Http\NeuronHttpFactory;

/**
 * Neuron AI / RAG / MCP 模块配置加载器。
 *
 * 读取 APP_PATH/config/neuron_ai.php（可选），环境变量优先。
 */
final class NeuronAiConfig
{
    /** @param array<string, mixed> $config */
    private function __construct(
        private readonly array $config,
    ) {
    }

    public static function load(): self
    {
        return new self(ApplicationConfig::loadPhpConfig('neuron_ai.php'));
    }

    /**
     * @param array<string, mixed> $config
     *
     * @internal 单测 / 脚本注入
     */
    public static function fromArray(array $config): self
    {
        return new self($config);
    }

    /** @return array<string, mixed> */
    public function ragSection(): array
    {
        return (array) ($this->config['rag'] ?? []);
    }

    /** @return array<string, mixed> */
    public function mcpSection(): array
    {
        return (array) ($this->config['mcp'] ?? []);
    }

    /** @return array<string, mixed> */
    public function neuronSection(): array
    {
        return (array) ($this->config['neuron'] ?? []);
    }

    public function vectorStoreDriver(): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->ragSection(),
            'vector_store',
            NeuronAiRagEnv::VECTOR_STORE,
            NeuronAiVectorStoreName::FILE,
        );
    }

    public function fileStorePath(): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->ragSection(),
            'file_store_path',
            NeuronAiRagEnv::FILE_STORE_PATH,
            sys_get_temp_dir() . '/swoolefy_rag',
        );
    }

    public function defaultTopK(): int
    {
        return ApplicationConfig::pickIntEnvFirst(
            $this->ragSection(),
            'default_top_k',
            NeuronAiRagEnv::DEFAULT_TOP_K,
            5,
        );
    }

    public function embeddingModel(): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->ragSection(),
            'embedding_model',
            NeuronAiRagEnv::EMBEDDING_MODEL,
            'text-embedding-3-small',
        );
    }

    /** @return array<string, mixed> */
    public function meilisearchSection(): array
    {
        return (array) ($this->ragSection()[NeuronAiVectorStoreName::MEILISEARCH] ?? []);
    }

    public function meilisearchHost(): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->meilisearchSection(),
            'host',
            NeuronAiRagEnv::MEILISEARCH_HOST,
            'http://localhost:7700',
        );
    }

    public function meilisearchKey(): ?string
    {
        $key = ApplicationConfig::pickStringEnvFirst(
            $this->meilisearchSection(),
            'key',
            NeuronAiRagEnv::MEILISEARCH_KEY,
            '',
        );

        return $key !== '' ? $key : null;
    }

    public function meilisearchEmbedder(): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->meilisearchSection(),
            'embedder',
            NeuronAiRagEnv::MEILISEARCH_EMBEDDER,
            'default',
        );
    }

    public function meilisearchDimension(): int
    {
        return ApplicationConfig::pickIntEnvFirst(
            $this->meilisearchSection(),
            'dimension',
            NeuronAiRagEnv::MEILISEARCH_DIMENSION,
            1024,
        );
    }

    /** @return array<string, mixed> */
    public function phpvectorSection(): array
    {
        return (array) ($this->ragSection()[NeuronAiVectorStoreName::PHP_VECTOR] ?? []);
    }

    public function phpvectorPath(): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->phpvectorSection(),
            'path',
            NeuronAiRagEnv::PHPVECTOR_PATH,
            sys_get_temp_dir() . '/swoolefy_phpvector',
        );
    }

    /** @return array<string, mixed> */
    public function mariadbSection(): array
    {
        return (array) ($this->ragSection()[NeuronAiVectorStoreName::MARIADB] ?? []);
    }

    public function mariadbComponent(): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->mariadbSection(),
            'component',
            NeuronAiRagEnv::MARIADB_COMPONENT,
            'db',
        );
    }

    public function mariadbTableName(): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->mariadbSection(),
            'table_name',
            NeuronAiRagEnv::MARIADB_TABLE_NAME,
            'rag_documents',
        );
    }

    /** @return array<string, mixed> */
    public function pineconeSection(): array
    {
        return (array) ($this->ragSection()[NeuronAiVectorStoreName::PINECONE] ?? []);
    }

    public function pineconeKey(): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->pineconeSection(),
            'key',
            NeuronAiRagEnv::PINECONE_KEY,
            '',
        );
    }

    public function pineconeIndexUrl(): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->pineconeSection(),
            'index_url',
            NeuronAiRagEnv::PINECONE_INDEX_URL,
            '',
        );
    }

    public function pineconeVersion(): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->pineconeSection(),
            'version',
            NeuronAiRagEnv::PINECONE_VERSION,
            '2025-04',
        );
    }

    /** @return array<string, mixed> */
    public function qdrantSection(): array
    {
        return (array) ($this->ragSection()[NeuronAiVectorStoreName::QDRANT] ?? []);
    }

    public function qdrantBaseUrl(): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->qdrantSection(),
            'base_url',
            NeuronAiRagEnv::QDRANT_BASE_URL,
            'http://localhost:6333',
        );
    }

    public function qdrantKey(): ?string
    {
        $key = ApplicationConfig::pickStringEnvFirst(
            $this->qdrantSection(),
            'key',
            NeuronAiRagEnv::QDRANT_KEY,
            '',
        );

        return $key !== '' ? $key : null;
    }

    public function qdrantDimension(): int
    {
        return ApplicationConfig::pickIntEnvFirst(
            $this->qdrantSection(),
            'dimension',
            NeuronAiRagEnv::QDRANT_DIMENSION,
            1536,
        );
    }

    /**
     * rag.milvus section (Aliyun / self-hosted Milvus).
     *
     * @return array<string, mixed>
     */
    public function milvusSection(): array
    {
        return (array) ($this->ragSection()[NeuronAiVectorStoreName::MILVUS] ?? []);
    }

    /** Milvus REST base URI (env MILVUS_URI overrides config). */
    public function milvusUri(): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->milvusSection(),
            'uri',
            NeuronAiRagEnv::MILVUS_URI,
            'http://localhost:19530',
        );
    }

    /** Username for Aliyun-style auth; null when unset. */
    public function milvusUser(): ?string
    {
        $val = ApplicationConfig::pickStringEnvFirst(
            $this->milvusSection(),
            'user',
            NeuronAiRagEnv::MILVUS_USER,
            '',
        );

        return $val !== '' ? $val : null;
    }

    /** Password paired with milvusUser(); null when unset. */
    public function milvusPassword(): ?string
    {
        $val = ApplicationConfig::pickStringEnvFirst(
            $this->milvusSection(),
            'password',
            NeuronAiRagEnv::MILVUS_PASSWORD,
            '',
        );

        return $val !== '' ? $val : null;
    }

    /** Optional token auth (alternative to user+password). */
    public function milvusToken(): ?string
    {
        $val = ApplicationConfig::pickStringEnvFirst(
            $this->milvusSection(),
            'token',
            NeuronAiRagEnv::MILVUS_TOKEN,
            '',
        );

        return $val !== '' ? $val : null;
    }

    /** Logical database name inside the Milvus instance. */
    public function milvusDbName(): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->milvusSection(),
            'db_name',
            NeuronAiRagEnv::MILVUS_DB_NAME,
            'default',
        );
    }

    /** FLOAT_VECTOR dimension; must match embedding model. */
    public function milvusDimension(): int
    {
        return ApplicationConfig::pickIntEnvFirst(
            $this->milvusSection(),
            'dimension',
            NeuronAiRagEnv::MILVUS_DIMENSION,
            1536,
        );
    }

    public function maxLocalProcesses(): int
    {
        return max(1, ApplicationConfig::pickIntEnvFirst(
            $this->mcpSection(),
            'max_local_processes',
            'MCP_MAX_LOCAL_PROCESSES',
            2,
        ));
    }

    public function httpClient(): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->neuronSection(),
            'http_client',
            NeuronHttpFactory::ENV_HTTP_CLIENT,
            NeuronHttpFactory::CLIENT_SWOOLE,
        );
    }

    /** 默认 Provider 别名（ai_model_providers 的 key）。 */
    public function defaultProviderName(): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->neuronSection(),
            'default_provider',
            'NEURON_DEFAULT_PROVIDER',
            NeuronAiProviderName::ANTHROPIC,
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function aiModelProviders(): array
    {
        $providers = $this->neuronSection()['ai_model_providers'] ?? [];

        return is_array($providers) ? $providers : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function providerConfig(string $alias): ?array
    {
        $config = $this->aiModelProviders()[$alias] ?? null;

        return is_array($config) ? $config : null;
    }
}
