<?php

declare(strict_types=1);

namespace Swoolefy\Support\Neuron;

/**
 * Neuron RAG / 向量库相关环境变量（从 .env 经 env() 读取）。
 *
 * 常量供 neuron_ai.php 配置引用；静态方法供运行时直接读取 .env。
 */
final class NeuronAiRagEnv
{
    public const VECTOR_STORE = 'RAG_VECTOR_STORE';

    public const FILE_STORE_PATH = 'RAG_FILE_STORE_PATH';

    public const DEFAULT_TOP_K = 'RAG_DEFAULT_TOP_K';

    public const EMBEDDING_MODEL = 'RAG_EMBEDDING_MODEL';

    /** Embedding 向量维度；须与各 vector_stores.*.dimension 一致。 */
    public const EMBEDDING_DIMENSION = 'RAG_EMBEDDING_DIMENSION';

    /** 单测 / 本地演示允许 FakeEmbeddings（生产勿开）。 */
    public const ALLOW_FAKE_EMBEDDINGS = 'NEURON_ALLOW_FAKE_EMBEDDINGS';

    /** 生产环境须为 true：RAG 知识库与 Redis ChatHistory 按 tenantId 隔离。 */
    public const REQUIRE_TENANT_ISOLATION = 'RAG_REQUIRE_TENANT_ISOLATION';

    public const MEILISEARCH_HOST = 'MEILISEARCH_HOST';

    public const MEILISEARCH_KEY = 'MEILISEARCH_KEY';

    public const MEILISEARCH_EMBEDDER = 'MEILISEARCH_EMBEDDER';

    public const MEILISEARCH_DIMENSION = 'MEILISEARCH_DIMENSION';

    public const PHPVECTOR_PATH = 'RAG_PHPVECTOR_PATH';

    public const MARIADB_COMPONENT = 'RAG_MARIADB_COMPONENT';

    public const MARIADB_TABLE_NAME = 'RAG_MARIADB_TABLE_NAME';

    public const PGVECTOR_COMPONENT = 'RAG_PGVECTOR_COMPONENT';

    public const PGVECTOR_TABLE_NAME = 'RAG_PGVECTOR_TABLE_NAME';

    public const PGVECTOR_DIMENSION = 'RAG_PGVECTOR_DIMENSION';

    public const PGVECTOR_METRIC = 'RAG_PGVECTOR_METRIC';

    public const PINECONE_KEY = 'PINECONE_API_KEY';

    public const PINECONE_INDEX_URL = 'PINECONE_INDEX_URL';

    public const PINECONE_VERSION = 'PINECONE_API_VERSION';

    public const QDRANT_BASE_URL = 'QDRANT_BASE_URL';

    public const QDRANT_KEY = 'QDRANT_API_KEY';

    public const QDRANT_DIMENSION = 'QDRANT_DIMENSION';

    /** Milvus HTTP endpoint, e.g. http://c-xxxx.milvus.aliyuncs.com:19530 */
    public const MILVUS_URI = 'MILVUS_URI';

    /** Milvus username (Aliyun: pair with MILVUS_PASSWORD). */
    public const MILVUS_USER = 'MILVUS_USER';

    /** Milvus password (used with MILVUS_USER as Bearer user:password). */
    public const MILVUS_PASSWORD = 'MILVUS_PASSWORD';

    /** Optional JWT / API token; alternative to user+password. */
    public const MILVUS_TOKEN = 'MILVUS_TOKEN';

    /** Milvus database name (default: default). */
    public const MILVUS_DB_NAME = 'MILVUS_DB_NAME';

    /** Vector dimension; must match embedding model output size. */
    public const MILVUS_DIMENSION = 'MILVUS_DIMENSION';

    public static function vectorStore(?string $default = null): ?string
    {
        return self::readString(self::VECTOR_STORE, $default ?? '');
    }

    public static function fileStorePath(string $default = '/tmp/swoolefy_rag'): string
    {
        return self::readString(self::FILE_STORE_PATH, $default);
    }

    public static function defaultTopK(int $default = 5): int
    {
        return self::readInt(self::DEFAULT_TOP_K, $default);
    }

    public static function embeddingModel(string $default = 'text-embedding-3-small'): string
    {
        return self::readString(self::EMBEDDING_MODEL, $default);
    }

    public static function embeddingDimension(int $default = 1536): int
    {
        return max(1, self::readInt(self::EMBEDDING_DIMENSION, $default));
    }

    public static function allowFakeEmbeddings(bool $default = false): bool
    {
        return self::readBool(self::ALLOW_FAKE_EMBEDDINGS, $default);
    }

    public static function requireTenantIsolation(bool $default = true): bool
    {
        return self::readBool(self::REQUIRE_TENANT_ISOLATION, $default);
    }

    public static function meilisearchHost(string $default = 'http://localhost:7700'): string
    {
        return self::readString(self::MEILISEARCH_HOST, $default);
    }

    public static function meilisearchKey(?string $default = null): ?string
    {
        $value = self::readString(self::MEILISEARCH_KEY, $default ?? '');

        return $value !== '' ? $value : $default;
    }

    public static function meilisearchEmbedder(string $default = 'default'): string
    {
        return self::readString(self::MEILISEARCH_EMBEDDER, $default);
    }

    public static function meilisearchDimension(int $default = 1536): int
    {
        return self::readInt(self::MEILISEARCH_DIMENSION, $default);
    }

    public static function phpvectorPath(string $default = '/tmp/swoolefy_phpvector'): string
    {
        return self::readString(self::PHPVECTOR_PATH, $default);
    }

    public static function mariadbComponent(string $default = 'db'): string
    {
        return self::readString(self::MARIADB_COMPONENT, $default);
    }

    public static function mariadbTableName(string $default = 'rag_documents'): string
    {
        return self::readString(self::MARIADB_TABLE_NAME, $default);
    }

    public static function pgvectorComponent(string $default = 'pg'): string
    {
        return self::readString(self::PGVECTOR_COMPONENT, $default);
    }

    public static function pgvectorTableName(string $default = 'rag_documents'): string
    {
        return self::readString(self::PGVECTOR_TABLE_NAME, $default);
    }

    public static function pgvectorDimension(int $default = 1536): int
    {
        return self::readInt(self::PGVECTOR_DIMENSION, $default);
    }

    public static function pgvectorMetric(string $default = 'cosine'): string
    {
        return self::readString(self::PGVECTOR_METRIC, $default);
    }

    public static function pineconeKey(?string $default = null): ?string
    {
        $value = self::readString(self::PINECONE_KEY, $default ?? '');

        return $value !== '' ? $value : $default;
    }

    public static function pineconeIndexUrl(?string $default = null): ?string
    {
        $value = self::readString(self::PINECONE_INDEX_URL, $default ?? '');

        return $value !== '' ? $value : $default;
    }

    public static function pineconeVersion(string $default = '2025-04'): string
    {
        return self::readString(self::PINECONE_VERSION, $default);
    }

    public static function qdrantBaseUrl(string $default = 'http://localhost:6333'): string
    {
        return self::readString(self::QDRANT_BASE_URL, $default);
    }

    public static function qdrantKey(?string $default = null): ?string
    {
        $value = self::readString(self::QDRANT_KEY, $default ?? '');

        return $value !== '' ? $value : $default;
    }

    public static function qdrantDimension(int $default = 1536): int
    {
        return self::readInt(self::QDRANT_DIMENSION, $default);
    }

    public static function milvusUri(string $default = 'http://localhost:19530'): string
    {
        return self::readString(self::MILVUS_URI, $default);
    }

    public static function milvusUser(?string $default = null): ?string
    {
        $value = self::readString(self::MILVUS_USER, $default ?? '');

        return $value !== '' ? $value : $default;
    }

    public static function milvusPassword(?string $default = null): ?string
    {
        $value = self::readString(self::MILVUS_PASSWORD, $default ?? '');

        return $value !== '' ? $value : $default;
    }

    public static function milvusToken(?string $default = null): ?string
    {
        $value = self::readString(self::MILVUS_TOKEN, $default ?? '');

        return $value !== '' ? $value : $default;
    }

    public static function milvusDbName(string $default = 'default'): string
    {
        return self::readString(self::MILVUS_DB_NAME, $default);
    }

    public static function milvusDimension(int $default = 1536): int
    {
        return self::readInt(self::MILVUS_DIMENSION, $default);
    }

    /**
     * 从 .env / 进程环境读取（经 env()）。
     */
    private static function read(string $key, mixed $default = null): mixed
    {
        return env($key, $default);
    }

    private static function readString(string $key, string $default = ''): string
    {
        $value = self::read($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    private static function readInt(string $key, int $default): int
    {
        $value = self::read($key, null);

        return is_numeric($value) ? (int) $value : $default;
    }

    private static function readBool(string $key, bool $default): bool
    {
        $value = self::read($key, null);
        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
