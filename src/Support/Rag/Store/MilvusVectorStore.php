<?php

declare(strict_types=1);

namespace Swoolefy\Support\Rag\Store;

use Milvus\Client;
use Milvus\DataType;
use Milvus\IndexType;
use Milvus\MetricType;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use RuntimeException;

use function array_chunk;
use function array_map;
use function in_array;
use function json_decode;

/**
 * Aliyun Milvus / self-hosted Milvus 2.x vector store (Neuron VectorStoreInterface).
 *
 * Production notes:
 * - Depends on mathsgod/milvus-client-php (REST API, works with Aliyun Milvus).
 * - One knowledgeBase => one Milvus collection (name sanitized by VectorStoreFactory).
 * - Collection is created lazily on first write/search when autoCreateCollection=true.
 * - Auth: user+password (Bearer user:password) OR token; Aliyun typically uses user/password.
 * - dimension MUST match the embedding model (e.g. text-embedding-3-small => 1536).
 *
 * Collection schema:
 *   id        VARCHAR(64) PK   — Neuron Document UUID
 *   content   VARCHAR          — chunk text (truncated to maxContentLength)
 *   embedding FLOAT_VECTOR     — vector field used for ANN search
 *   metadata  JSON             — sourceType / sourceName / custom fields
 *
 * @see https://help.aliyun.com/zh/milvus/
 * @see https://docs.neuron-ai.dev/rag/vector-store#implement-custom-vector-stores
 * @see https://github.com/mathsgod/milvus-client-php
 */
class MilvusVectorStore implements VectorStoreInterface
{
    /** Whether collection existence has been checked (or created) in this instance. */
    private bool $initialized = false;

    /**
     * @param Client $client                Milvus REST client (uri / user / password / token / db)
     * @param string $collectionName        Target collection (= knowledgeBase after sanitize)
     * @param int    $dimension             Vector dim; must match embedder output size
     * @param int    $topK                  Default similaritySearch limit
     * @param string $metricType            COSINE (default) | L2 | IP — COSINE returns similarity-like distance
     * @param string $indexType             AUTOINDEX is recommended on Aliyun managed Milvus
     * @param bool   $autoCreateCollection  Create schema+index if collection is missing
     * @param int    $maxContentLength      VARCHAR limit for content field
     * @param int    $batchSize             Upsert batch size (avoid oversized HTTP payloads)
     */
    public function __construct(
        protected Client $client,
        protected string $collectionName,
        protected int $dimension = 1536,
        protected int $topK = 5,
        protected string $metricType = MetricType::COSINE,
        protected string $indexType = IndexType::AUTOINDEX,
        protected bool $autoCreateCollection = true,
        protected int $maxContentLength = 65535,
        protected int $batchSize = 100,
    ) {
    }

    /**
     * Build from neuron_ai.php / env params (used by VectorStoreFactory).
     *
     * Expected keys: uri, user, password, token, db_name, collection_name,
     * dimension, top_k, metric_type, index_type, auto_create_collection.
     *
     * @param array<string, mixed> $params
     */
    public static function make(array $params): self
    {
        if (!class_exists(Client::class)) {
            throw new RuntimeException(
                'Milvus vector store requires mathsgod/milvus-client-php. Run: composer require mathsgod/milvus-client-php',
            );
        }

        // Aliyun: uri like http://c-xxxx.milvus.aliyuncs.com:19530, auth via user+password.
        // Self-hosted: often http://localhost:19530 with optional token.
        $client = new Client(
            uri: $params['uri'] ?? 'http://localhost:19530',
            user: $params['user'] ?? null,
            password: $params['password'] ?? null,
            db_name: $params['db_name'] ?? 'default',
            token: $params['token'] ?? null,
        );

        return new self(
            client: $client,
            collectionName: $params['collection_name'] ?? 'rag_documents',
            dimension: (int) ($params['dimension'] ?? 1536),
            topK: (int) ($params['top_k'] ?? 5),
            metricType: $params['metric_type'] ?? MetricType::COSINE,
            indexType: $params['index_type'] ?? IndexType::AUTOINDEX,
            autoCreateCollection: (bool) ($params['auto_create_collection'] ?? true),
        );
    }

    public function addDocument(Document $document): VectorStoreInterface
    {
        return $this->addDocuments([$document]);
    }

    /**
     * Upsert documents (idempotent by id). Documents must already have embeddings.
     *
     * @param Document[] $documents
     */
    public function addDocuments(array $documents): VectorStoreInterface
    {
        $this->ensureCollection();

        $chunks = array_chunk($documents, $this->batchSize);

        foreach ($chunks as $chunk) {
            $data = array_map(function (Document $document): array {
                $embedding = $document->getEmbedding();
                if (empty($embedding)) {
                    throw new RuntimeException(
                        "Document [{$document->getId()}] has no embedding. Embed documents before adding to Milvus.",
                    );
                }

                // sourceType/sourceName live in metadata JSON so deleteBy() can filter them.
                $metadata = [
                    'sourceType' => $document->getSourceType(),
                    'sourceName' => $document->getSourceName(),
                ];
                if (!empty($document->metadata)) {
                    $metadata = array_merge($metadata, $document->metadata);
                }

                return [
                    'id' => (string) $document->getId(),
                    'content' => mb_substr($document->getContent(), 0, $this->maxContentLength),
                    'embedding' => $embedding,
                    'metadata' => $metadata,
                ];
            }, $chunk);

            // upsert: insert or replace by primary key (safe for re-ingest of the same chunk ids)
            $this->client->upsert($this->collectionName, $data);
        }

        return $this;
    }

    /**
     * @deprecated Use deleteBy() instead.
     */
    public function deleteBySource(string $sourceType, string $sourceName): VectorStoreInterface
    {
        return $this->deleteBy($sourceType, $sourceName);
    }

    /**
     * Delete entities by source metadata (Milvus boolean expression on JSON field).
     *
     * Filter syntax follows Milvus scalar filter, e.g.:
     *   metadata["sourceType"] == "file" and metadata["sourceName"] == "readme.md"
     */
    public function deleteBy(string $sourceType, ?string $sourceName = null): VectorStoreInterface
    {
        $this->ensureCollection();

        $filter = "metadata[\"sourceType\"] == \"{$sourceType}\"";
        if ($sourceName !== null) {
            $filter .= " and metadata[\"sourceName\"] == \"{$sourceName}\"";
        }

        $this->client->delete(
            collection_name: $this->collectionName,
            filter: $filter,
        );

        return $this;
    }

    /**
     * ANN search; with metric COSINE, Milvus returns distance as similarity score (higher is better).
     *
     * @param float[] $embedding
     * @return Document[]
     */
    public function similaritySearch(array $embedding): iterable
    {
        $this->ensureCollection();

        $results = $this->client->search(
            collection_name: $this->collectionName,
            data: [$embedding],
            anns_field: 'embedding',
            limit: $this->topK,
            output_fields: ['id', 'content', 'metadata'],
        );

        return array_map(function (array $item): Document {
            $document = new Document($item['content'] ?? '');
            $document->id = (string) $item['id'];
            // COSINE: distance field is already a similarity-like score; L2 would need conversion.
            $document->score = (float) ($item['distance'] ?? 0.0);

            $metadata = $item['metadata'] ?? [];
            // Some Milvus deployments return JSON fields as encoded strings.
            if (is_string($metadata)) {
                $metadata = json_decode($metadata, true) ?: [];
            }

            $document->sourceType = $metadata['sourceType'] ?? 'manual';
            $document->sourceName = $metadata['sourceName'] ?? 'manual';

            foreach ($metadata as $name => $value) {
                if (!in_array($name, ['sourceType', 'sourceName'], true)) {
                    $document->addMetadata($name, $value);
                }
            }

            return $document;
        }, $results);
    }

    /**
     * Drop the underlying Milvus collection (cleanup / tests only).
     */
    public function dropCollection(): void
    {
        if ($this->client->hasCollection($this->collectionName)) {
            $this->client->dropCollection($this->collectionName);
        }
        $this->initialized = false;
    }

    public function getCollectionName(): string
    {
        return $this->collectionName;
    }

    /**
     * Ensure collection exists: hasCollection check, then create schema + vector index if needed.
     * createCollection with indexParams loads the collection for search on managed Milvus.
     */
    private function ensureCollection(): void
    {
        if ($this->initialized) {
            return;
        }

        if ($this->client->hasCollection($this->collectionName)) {
            $this->initialized = true;
            return;
        }

        if (!$this->autoCreateCollection) {
            throw new RuntimeException(
                "Milvus collection [{$this->collectionName}] does not exist and auto_create_collection is disabled.",
            );
        }

        // auto_id=false: we supply Neuron Document UUIDs as primary keys.
        $schema = $this->client->createSchema(auto_id: false)
            ->addField('id', DataType::VARCHAR, is_primary: true, max_length: 64)
            ->addField('content', DataType::VARCHAR, max_length: $this->maxContentLength, nullable: true)
            ->addField('embedding', DataType::FLOAT_VECTOR, dim: $this->dimension)
            ->addField('metadata', DataType::JSON, nullable: true);

        $indexParams = $this->client->prepareIndexParams()
            ->addIndex(
                field_name: 'embedding',
                index_name: 'idx_embedding',
                index_type: $this->indexType,
                metric_type: $this->metricType,
            );

        $this->client->createCollection(
            collection_name: $this->collectionName,
            schema: $schema,
            index_params: $indexParams,
        );

        $this->initialized = true;
    }
}
