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

use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorSimilarity;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use PDO;
use RuntimeException;

use function array_chunk;
use function count;
use function in_array;
use function json_decode;
use function json_encode;
use function sprintf;

/**
 * PostgreSQL + pgvector 向量存储（实现 Neuron VectorStoreInterface）。
 *
 * 生产说明：
 * - 依赖 PostgreSQL 扩展 pgvector（https://github.com/pgvector/pgvector）。
 * - 一个 knowledgeBase 对应一张物理表，表名由 VectorStoreFactory 组装：
 *   `{table_name}_{tenantId}_{knowledgeBase}`，与 MariaDBVectorStore 的隔离方式一致。
 * - 表结构、pgvector 扩展和向量索引必须在上线前提前处理好；运行期不会自动创建。
 * - `setupTable()` 仅作为离线迁移 / 运维脚本可复用的 DDL helper，不会被 add/search 自动调用。
 * - 默认使用 cosine 距离（operator `<=>`）和 HNSW 索引，适合 OpenAI/兼容 embedding 的相似度检索。
 * - dimension 必须与 Embedding 输出维度一致；如 text-embedding-3-small 默认为 1536。
 *
 * 表结构：
 *   id          TEXT PRIMARY KEY  — Neuron Document UUID
 *   content     TEXT              — 分块文本
 *   source_type TEXT              — Document sourceType
 *   source_name TEXT              — Document sourceName
 *   metadata    JSONB             — 业务扩展元数据
 *   embedding   vector(dim)       — pgvector 向量字段
 *
 * 上线前 DDL 示例：
 *   CREATE EXTENSION IF NOT EXISTS vector;
 *   CREATE TABLE IF NOT EXISTS rag_documents_tenant_product_kb (... embedding vector(1536) NOT NULL);
 *   CREATE INDEX IF NOT EXISTS idx_rag_documents_tenant_product_kb_embedding
 *     ON rag_documents_tenant_product_kb USING hnsw (embedding vector_cosine_ops);
 *
 * 距离 / 分数：
 * - cosine: pgvector `<=>` 返回 cosine distance，越小越相似；score = 1 - distance。
 * - l2:     pgvector `<->` 返回欧氏距离；score 使用 Neuron VectorSimilarity::similarityFromDistance()。
 * - ip:     pgvector `<#>` 返回 negative inner product；score = -distance。
 *
 * @see https://github.com/pgvector/pgvector
 * @see https://docs.neuron-ai.dev/rag/vector-store#implement-custom-vector-stores
 */
final class PgVectorStore implements VectorStoreInterface
{
    public const METRIC_COSINE = 'cosine';

    public const METRIC_L2 = 'l2';

    public const METRIC_INNER_PRODUCT = 'ip';

    /** 写入时分批，避免单次事务过大。 */
    private int $batchSize;

    /**
     * @param PDO    $pdo                 PostgreSQL PDO，建议来自 Config/component/database.php 的 pg 组件
     * @param string $tableName           物理表名；仅支持简单标识符，避免动态 SQL 注入
     * @param int    $dimension           向量维度，须与 Embedding 输出一致
     * @param int    $topK                similaritySearch 默认返回条数
     * @param string $metric              cosine | l2 | ip
     * @param int    $batchSize           addDocuments 分批大小
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $tableName = 'rag_documents',
        private readonly int $dimension = 1536,
        private readonly int $topK = 5,
        private readonly string $metric = self::METRIC_COSINE,
        int $batchSize = 100,
    ) {
        self::assertSafeIdentifier($this->tableName);
        if (!in_array($this->metric, [self::METRIC_COSINE, self::METRIC_L2, self::METRIC_INNER_PRODUCT], true)) {
            throw new RuntimeException("Unsupported pgvector metric [{$this->metric}].");
        }
        $this->batchSize = max(1, $batchSize);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * 从 neuron_ai.php / 环境变量参数构建实例（VectorStoreFactory 调用入口）。
     *
     * 期望键：
     * - pdo: PostgreSQL PDO
     * - table_name: 物理表名
     * - dimension/top_k/metric
     * - batch_size
     *
     * @param array<string, mixed> $params
     */
    public static function make(array $params): self
    {
        $pdo = $params['pdo'] ?? null;
        if (!$pdo instanceof PDO) {
            throw new RuntimeException('PgVectorStore requires a PDO instance.');
        }

        return new self(
            pdo: $pdo,
            tableName: (string) ($params['table_name'] ?? 'rag_documents'),
            dimension: (int) ($params['dimension'] ?? 1536),
            topK: (int) ($params['top_k'] ?? 5),
            metric: (string) ($params['metric'] ?? self::METRIC_COSINE),
            batchSize: (int) ($params['batch_size'] ?? 100),
        );
    }

    /**
     * 创建 pgvector 扩展、表与索引。
     *
     * 注意：业务读写路径不会自动调用本方法。生产上线前应在迁移脚本 / DBA 流程中提前执行，
     * 避免在线请求承担 DDL、扩展安装和索引构建成本，也避免业务账号需要 CREATE EXTENSION 权限。
     */
    public function setupTable(): void
    {
        $this->pdo->exec('CREATE EXTENSION IF NOT EXISTS vector');

        $this->pdo->exec(sprintf(
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS %s (
                    id TEXT PRIMARY KEY,
                    content TEXT NOT NULL,
                    source_type TEXT NOT NULL DEFAULT 'manual',
                    source_name TEXT NOT NULL DEFAULT 'manual',
                    metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                    embedding vector(%d) NOT NULL
                )
                SQL,
            $this->tableName,
            $this->dimension,
        ));

        $indexName = 'idx_' . $this->tableName . '_embedding';
        self::assertSafeIdentifier($indexName);
        $this->pdo->exec(sprintf(
            'CREATE INDEX IF NOT EXISTS %s ON %s USING hnsw (embedding %s)',
            $indexName,
            $this->tableName,
            $this->indexOperatorClass(),
        ));
    }

    /** 删除底层表（清理 / 单测专用，生产慎用）。 */
    public function dropTable(): void
    {
        $this->pdo->exec(sprintf('DROP TABLE IF EXISTS %s', $this->tableName));
    }

    /** 当前物理表名。 */
    public function getTableName(): string
    {
        return $this->tableName;
    }

    /** {@inheritdoc} 单条写入，委托 addDocuments。 */
    public function addDocument(Document $document): VectorStoreInterface
    {
        return $this->addDocuments([$document]);
    }

    /**
     * 批量 upsert 文档（按 id 幂等：同 id 覆盖更新）。
     *
     * 前置条件：Document 必须已 embed（embedding 非空且维度正确），否则抛 RuntimeException。
     *
     * @param Document[] $documents
     */
    public function addDocuments(array $documents): VectorStoreInterface
    {
        if ($documents === []) {
            return $this;
        }

        $stmt = $this->pdo->prepare(sprintf(
            <<<'SQL'
                INSERT INTO %s (id, content, source_type, source_name, metadata, embedding)
                VALUES (:id, :content, :source_type, :source_name, CAST(:metadata AS jsonb), CAST(:embedding AS vector))
                ON CONFLICT (id) DO UPDATE SET
                    content = EXCLUDED.content,
                    source_type = EXCLUDED.source_type,
                    source_name = EXCLUDED.source_name,
                    metadata = EXCLUDED.metadata,
                    embedding = EXCLUDED.embedding
                SQL,
            $this->tableName,
        ));

        foreach (array_chunk($documents, $this->batchSize) as $chunk) {
            foreach ($chunk as $document) {
                $embedding = $document->getEmbedding();
                $this->assertEmbedding($embedding, (string) $document->getId());

                $stmt->execute([
                    ':id' => (string) $document->getId(),
                    ':content' => $document->getContent(),
                    ':source_type' => $document->getSourceType(),
                    ':source_name' => $document->getSourceName(),
                    ':metadata' => json_encode($document->metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    ':embedding' => self::embeddingLiteral($embedding),
                ]);
            }
        }

        return $this;
    }

    /**
     * 按 Document 来源删除。
     *
     * sourceName 为 null 时删除该 sourceType 的所有文档；不为 null 时精确匹配来源名称。
     */
    public function deleteBy(string $sourceType, ?string $sourceName = null): VectorStoreInterface
    {
        if ($sourceName !== null) {
            $stmt = $this->pdo->prepare(sprintf(
                'DELETE FROM %s WHERE source_type = :source_type AND source_name = :source_name',
                $this->tableName,
            ));
            $stmt->execute([':source_type' => $sourceType, ':source_name' => $sourceName]);

            return $this;
        }

        $stmt = $this->pdo->prepare(sprintf(
            'DELETE FROM %s WHERE source_type = :source_type',
            $this->tableName,
        ));
        $stmt->execute([':source_type' => $sourceType]);

        return $this;
    }

    /**
     * 向量相似度检索。
     *
     * @param float[] $embedding 查询向量
     *
     * @return Document[]
     */
    public function similaritySearch(array $embedding): iterable
    {
        $this->assertEmbedding($embedding, 'query');

        $stmt = $this->pdo->prepare(sprintf(
            <<<'SQL'
                SELECT id, content, source_type, source_name, metadata,
                       embedding %s CAST(:embedding AS vector) AS distance
                FROM %s
                ORDER BY embedding %s CAST(:embedding AS vector)
                LIMIT %d
                SQL,
            $this->distanceOperator(),
            $this->tableName,
            $this->distanceOperator(),
            $this->topK,
        ));
        $stmt->execute([':embedding' => self::embeddingLiteral($embedding)]);

        $documents = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $document = new Document((string) ($row['content'] ?? ''));
            $document->id = (string) ($row['id'] ?? '');
            $document->sourceType = (string) ($row['source_type'] ?? 'manual');
            $document->sourceName = (string) ($row['source_name'] ?? 'manual');
            $document->score = $this->scoreFromDistance((float) ($row['distance'] ?? 0.0));

            $metadata = json_decode((string) ($row['metadata'] ?? '{}'), true);
            if (is_array($metadata)) {
                foreach ($metadata as $key => $value) {
                    if (!is_string($key) || in_array($key, ['content', 'sourceType', 'sourceName', 'score', 'embedding', 'id'], true)) {
                        continue;
                    }
                    if (is_string($value) || is_int($value)) {
                        $document->addMetadata($key, $value);
                    }
                }
            }

            $documents[] = $document;
        }

        return $documents;
    }

    /** pgvector 字面量，形如 [0.1,0.2,0.3]。 */
    public static function embeddingLiteral(array $embedding): string
    {
        return '[' . implode(',', array_map(static fn (mixed $value): string => (string) (float) $value, $embedding)) . ']';
    }

    /** 动态 SQL 表名/索引名只允许简单 PostgreSQL 标识符。 */
    private static function assertSafeIdentifier(string $identifier): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new RuntimeException("Invalid PostgreSQL identifier [{$identifier}].");
        }
    }

    /** 校验向量已写入且维度匹配，尽早暴露配置错误。 */
    private function assertEmbedding(array $embedding, string $documentId): void
    {
        if ($embedding === []) {
            throw new RuntimeException("Document [{$documentId}] has no embedding. Embed documents before adding to PgVector.");
        }

        if (count($embedding) !== $this->dimension) {
            throw new RuntimeException(
                "Document [{$documentId}] embedding dimension mismatch: expected {$this->dimension}, got " . count($embedding),
            );
        }
    }

    private function distanceOperator(): string
    {
        return match ($this->metric) {
            self::METRIC_L2 => '<->',
            self::METRIC_INNER_PRODUCT => '<#>',
            default => '<=>',
        };
    }

    private function indexOperatorClass(): string
    {
        return match ($this->metric) {
            self::METRIC_L2 => 'vector_l2_ops',
            self::METRIC_INNER_PRODUCT => 'vector_ip_ops',
            default => 'vector_cosine_ops',
        };
    }

    private function scoreFromDistance(float $distance): float
    {
        return match ($this->metric) {
            self::METRIC_COSINE => 1.0 - $distance,
            self::METRIC_INNER_PRODUCT => -$distance,
            default => VectorSimilarity::similarityFromDistance($distance),
        };
    }
}
