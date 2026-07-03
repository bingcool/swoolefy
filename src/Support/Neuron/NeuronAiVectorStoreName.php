<?php

declare(strict_types=1);

namespace Swoolefy\Support\Neuron;

/**
 * neuron_ai.php → rag.vector_store 驱动名常量。
 */
final class NeuronAiVectorStoreName
{
    public const FILE = 'file';

    public const MEILISEARCH = 'meilisearch';

    public const PHP_VECTOR = 'phpvector';

    public const MARIADB = 'mariadb';

    public const PINECONE = 'pinecone';

    public const QDRANT = 'qdrant';
}
