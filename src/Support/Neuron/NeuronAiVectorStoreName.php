<?php

declare(strict_types=1);

namespace Swoolefy\Support\Neuron;

/**
 * 向量库驱动类型常量（NeuronAiVectorStoreName::*）。
 *
 * 配置约定（neuron_ai.php）：
 *   rag.default_vector_store            — 默认别名
 *   rag.vector_stores[alias]            — 别名对应的连接配置
 *   rag.vector_stores[alias].driver     — 可选；缺省时别名本身即驱动名
 *
 * 别名可与驱动名相同（如 meilisearch），也可自定义：
 *   'milvus_prod' => ['driver' => 'milvus', 'uri' => '...']
 *
 * @see VectorStoreFactory::make()
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
