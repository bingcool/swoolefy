<?php

declare(strict_types=1);

namespace Swoolefy\Support\Neuron;

/**
 * neuron_ai.php → rag.vector_store 驱动名常量。
 *
 * 同时作为 rag 配置块的 key（如 rag.milvus），与 VectorStoreFactory match 分支一致。
 */
final class NeuronAiVectorStoreName
{
    /** Local filesystem (dev / low volume). */
    public const FILE = 'file';

    /** Meilisearch hybrid engine used as vector index. */
    public const MEILISEARCH = 'meilisearch';

    /** Pure-PHP HNSW (neuron-core/php-vector). */
    public const PHP_VECTOR = 'phpvector';

    /** MariaDB >= 11.7 VECTOR column. */
    public const MARIADB = 'mariadb';

    /** Pinecone managed vector DB (namespace per knowledgeBase). */
    public const PINECONE = 'pinecone';

    /** Qdrant (collection URL per knowledgeBase). */
    public const QDRANT = 'qdrant';

    /** Aliyun Milvus / self-hosted Milvus 2.x (mathsgod/milvus-client-php). */
    public const MILVUS = 'milvus';
}
