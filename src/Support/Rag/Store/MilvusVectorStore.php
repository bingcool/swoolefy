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
 * 阿里云 Milvus / 自建 Milvus 2.x 向量存储（实现 Neuron VectorStoreInterface）。
 *
 * 生产说明：
 * - 依赖 mathsgod/milvus-client-php（REST API，兼容阿里云 Milvus 托管版）。
 * - 一个 knowledgeBase 对应一个 Milvus Collection（名称由 VectorStoreFactory 做 sanitize）。
 * - autoCreateCollection=true 时，首次写入/检索前懒创建 Collection + 向量索引。
 * - 鉴权：user+password（Bearer user:password）或 token；阿里云通常用 user/password。
 * - dimension 须与 Embedding 模型输出维度一致（如 text-embedding-3-small => 1536）。
 *
 * Collection 表结构：
 *   id        VARCHAR(64) PK   — Neuron Document UUID
 *   content   VARCHAR          — 分块文本（截断至 maxContentLength）
 *   embedding FLOAT_VECTOR     — 向量字段，用于 ANN 近似最近邻检索
 *   metadata  JSON             — sourceType / sourceName / 自定义扩展字段
 *
 * @see https://help.aliyun.com/zh/milvus/
 * @see https://docs.neuron-ai.dev/rag/vector-store#implement-custom-vector-stores
 * @see https://github.com/mathsgod/milvus-client-php
 */
class MilvusVectorStore implements VectorStoreInterface
{
    /** 本实例是否已检查（或创建）Collection，避免重复 RPC。 */
    private bool $initialized = false;

    /** Milvus collection 名（已合法化）。 */
    protected string $collectionName;

    /**
     * @param Client $client                Milvus REST 客户端（uri / user / password / token / db）
     * @param string $collectionName        目标 Collection 名（构造时会 {@see sanitizeCollectionName()}）
     * @param int    $dimension             向量维度，须与 Embedder 输出一致
     * @param int    $topK                  similaritySearch 默认返回条数
     * @param string $metricType            COSINE（默认）| L2 | IP — COSINE 返回类相似度距离
     * @param string $indexType             阿里云托管 Milvus 推荐 AUTOINDEX
     * @param bool   $autoCreateCollection  Collection 不存在时是否自动建表+索引
     * @param int    $maxContentLength      content 字段 VARCHAR 上限
     * @param int    $batchSize             upsert 分批大小，避免 HTTP 请求体过大
     */
    public function __construct(
        protected Client $client,
        string $collectionName,
        protected int $dimension = 1536,
        protected int $topK = 5,
        protected string $metricType = MetricType::COSINE,
        protected string $indexType = IndexType::AUTOINDEX,
        protected bool $autoCreateCollection = true,
        protected int $maxContentLength = 65535,
        protected int $batchSize = 100,
    ) {
        $this->collectionName = self::sanitizeCollectionName($collectionName);
    }

    /**
     * 将知识库名合法化为 Milvus collection 名。
     *
     * Milvus 仅允许 `[a-zA-Z0-9_]`，且须以字母或下划线开头。
     * {@see TenantScope::sanitize()} 会保留连字符 `-`，此处再替换为 `_`。
     */
    public static function sanitizeCollectionName(string $name): string
    {
        $name = preg_replace('/[^a-zA-Z0-9_]/', '_', $name) ?: 'rag_documents';
        $name = preg_replace('/_+/', '_', $name) ?: 'rag_documents';
        $name = trim($name, '_');
        if ($name === '') {
            return 'rag_documents';
        }

        if (!preg_match('/^[a-zA-Z_]/', $name)) {
            $name = 'c_' . $name;
        }

        return $name;
    }

    /**
     * 从 neuron_ai.php / 环境变量参数构建实例（VectorStoreFactory 调用入口）。
     *
     * 期望键：uri, user, password, token, db_name, collection_name,
     * dimension, top_k, metric_type, index_type, auto_create_collection。
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

        // 阿里云示例：uri 如 http://c-xxxx.milvus.aliyuncs.com:19530，鉴权用 user+password
        // 自建示例：常见 http://localhost:19530，可选 token
        $client = new Client(
            uri: $params['uri'] ?? 'http://localhost:19530',
            user: $params['user'] ?? null,
            password: $params['password'] ?? null,
            db_name: $params['db_name'] ?? 'default',
            token: $params['token'] ?? null,
        );

        return new self(
            client: $client,
            collectionName: (string) ($params['collection_name'] ?? 'rag_documents'),
            dimension: (int) ($params['dimension'] ?? 1536),
            topK: (int) ($params['top_k'] ?? 5),
            metricType: $params['metric_type'] ?? MetricType::COSINE,
            indexType: $params['index_type'] ?? IndexType::AUTOINDEX,
            autoCreateCollection: (bool) ($params['auto_create_collection'] ?? true),
        );
    }

    /** {@inheritdoc} 单条写入，委托 addDocuments。 */
    public function addDocument(Document $document): VectorStoreInterface
    {
        return $this->addDocuments([$document]);
    }

    /**
     * 批量 upsert 文档（按 id 幂等：同 id 覆盖更新）。
     *
     * 前置条件：Document 必须已 embed（embedding 非空），否则抛 RuntimeException。
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

                // sourceType/sourceName 写入 metadata JSON，供 deleteBy() 按来源过滤删除
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

            // upsert：主键存在则更新，不存在则插入（重复入库同一 chunk id 安全）
            $this->client->upsert($this->collectionName, $data);
        }

        return $this;
    }

    /**
     * @deprecated 请使用 deleteBy()
     */
    public function deleteBySource(string $sourceType, string $sourceName): VectorStoreInterface
    {
        return $this->deleteBy($sourceType, $sourceName);
    }

    /**
     * 按 metadata 中的来源信息删除实体（Milvus JSON 字段布尔表达式）。
     *
     * 过滤语法示例：
     *   metadata["sourceType"] == "file" and metadata["sourceName"] == "readme.md"
     */
    public function deleteBy(string $sourceType, ?string $sourceName = null): VectorStoreInterface
    {
        $this->ensureCollection();

        $filter = MilvusFilterExpr::deleteBySourceFilter($sourceType, $sourceName);

        $this->client->delete(
            collection_name: $this->collectionName,
            filter: $filter,
        );

        return $this;
    }

    /**
     * 向量近似最近邻检索（ANN）。
     *
     * metric 为 COSINE 时，Milvus 返回的 distance 可视为相似度分数（越大越相似）。
     *
     * @param float[] $embedding 查询向量
     *
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
            // COSINE：distance 已是类相似度分数；L2 需另行换算
            $document->score = (float) ($item['distance'] ?? 0.0);

            $metadata = $item['metadata'] ?? [];
            // 部分 Milvus 部署会将 JSON 字段以字符串形式返回，需二次 decode
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
     * 删除底层 Milvus Collection（清理 / 单测专用，生产慎用）。
     */
    public function dropCollection(): void
    {
        if ($this->client->hasCollection($this->collectionName)) {
            $this->client->dropCollection($this->collectionName);
        }
        $this->initialized = false;
    }

    /** 当前 Collection 名称（= knowledgeBase）。 */
    public function getCollectionName(): string
    {
        return $this->collectionName;
    }

    /**
     * 确保 Collection 存在：hasCollection 检查，缺失则创建 schema + 向量索引。
     *
     * createCollection 传入 indexParams 后，托管 Milvus 会自动 load Collection 供检索使用。
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

        // auto_id=false：主键使用 Neuron Document UUID，便于 upsert 幂等
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
