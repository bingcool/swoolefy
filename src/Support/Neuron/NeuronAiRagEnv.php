<?php

declare(strict_types=1);

namespace Swoolefy\Support\Neuron;

/**
 * Neuron RAG / 向量库相关环境变量名常量。
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

    public const MEILISEARCH_HOST = 'MEILISEARCH_HOST';

    public const MEILISEARCH_KEY = 'MEILISEARCH_KEY';

    public const MEILISEARCH_EMBEDDER = 'MEILISEARCH_EMBEDDER';

    public const MEILISEARCH_DIMENSION = 'MEILISEARCH_DIMENSION';

    public const PHPVECTOR_PATH = 'RAG_PHPVECTOR_PATH';

    public const MARIADB_COMPONENT = 'RAG_MARIADB_COMPONENT';

    public const MARIADB_TABLE_NAME = 'RAG_MARIADB_TABLE_NAME';

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
}
