# RAG（检索增强生成）

文档入库、向量存储、相似度检索与工作流节点。入库与检索共用同一 `RagFactory`，保证 **Embedding 与 VectorStore 配置一致**。

- 架构设计：[swoolefyAI.md](../../../docs/swoolefyAI.md) §4.10
- 配置：`Config/neuron_ai.php`（模版 `src/Stubs/neuron_ai.conf.stub.php`）→ `rag`
- 向量库文档：[Neuron Vector Store](https://docs.neuron-ai.dev/rag/vector-store)
- 关联：`Support/Neuron`（Embedding）、`Support/AI`（RAG 问答节点）

---

## 目录结构

```
Rag/
├── Factory/
│   ├── RagFactory.php           # VectorStore + Embeddings + Retrieval + Ingestion
│   └── VectorStoreFactory.php   # file / meilisearch / phpvector / mariadb / pgvector / pinecone / qdrant / milvus
├── Store/
│   ├── MeilisearchConfig.php
│   ├── PgVectorStore.php        # 自定义：PostgreSQL + pgvector
│   └── MilvusVectorStore.php    # 自定义：阿里云 / 自建 Milvus
├── Resolver/RagPdoResolver.php  # MariaDB / PostgreSQL PDO 组件解析
├── Ingestion/
│   ├── IngestionPipeline.php    # embed → addDocuments
│   ├── StringDocumentLoader.php
│   ├── FileDocumentLoader.php
│   └── IngestResult.php
├── Retrieval/RetrievalService.php
├── Tool/RetrievalToolFactory.php
├── Node/
│   ├── RagIngestNode.php
│   ├── RagRetrieveNode.php
│   └── RAGNode.php              # 检索 + Agent 问答
├── Builder/RAGNodeBuilder.php
├── Console/ingest_documents.php # 离线入库 CLI
└── Tests/
```

---

## 核心原理

```
文本 / Document
    │
    ▼ embed (EmbeddingFactory)
Document.embedding
    │
    ▼ VectorStoreFactory.make(knowledgeBase)
持久化（目录 / index / collection / 表）
    │
    ▼ SimilarityRetrieval
TopK Document → RetrievalService / RetrievalTool / RAGNode
```

**知识库隔离**：`knowledgeBase` 经 sanitize 后映射为子目录、indexUid、namespace、collection 或表名后缀。开启 `RAG_REQUIRE_TENANT_ISOLATION` 时物理名为 `{tenantId}_{kb}`。tenant 解析顺序为显式参数 → Workflow state/config 中的 `tenantId` → HTTP 透传头 `x-tenant-id` / `FrameworkContext`。

| 驱动 (`NeuronAiVectorStoreName`) | 隔离方式 |
|----------------------------------|----------|
| `file` | `{path}/{tenantId}_{kb}/` |
| `meilisearch` | indexUid = `{tenantId}_{kb}` |
| `phpvector` | `{path}/{tenantId}_{kb}/` |
| `mariadb` / `pgvector` | `{table}_{tenantId}_{kb}` |
| `pinecone` | namespace = `{tenantId}_{kb}` |
| `qdrant` / `milvus` | collection = `{tenantId}_{kb}` |

---

## 快速上手

### 配置

```php
// neuron_ai.php
'rag' => [
    // 默认向量库别名（env RAG_VECTOR_STORE 可覆盖）
    'default_vector_store' => NeuronAiVectorStoreName::FILE,
    'default_top_k' => 5,
    'embedding_model' => 'text-embedding-3-small',
    'embedding_dimension' => 1536,   // 须与各 vector_stores.*.dimension 一致
    'allow_fake_embeddings' => false, // 生产 false；单测 NEURON_ALLOW_FAKE_EMBEDDINGS=1
    // 已声明的向量库表：key = 别名；可选 driver（缺省时别名即驱动名）
    'vector_stores' => [
        NeuronAiVectorStoreName::FILE => [
            'path' => env(NeuronAiRagEnv::FILE_STORE_PATH, '/tmp/swoolefy_rag'),
        ],
        NeuronAiVectorStoreName::MILVUS => [
            'uri' => env(NeuronAiRagEnv::MILVUS_URI, 'http://localhost:19530'),
            // ...
        ],
        NeuronAiVectorStoreName::PGVECTOR => [
            'component' => env(NeuronAiRagEnv::PGVECTOR_COMPONENT, 'pg'),
            'table_name' => env(NeuronAiRagEnv::PGVECTOR_TABLE_NAME, 'rag_documents'),
            'dimension' => (int) env(NeuronAiRagEnv::PGVECTOR_DIMENSION, 1536),
            'metric' => env(NeuronAiRagEnv::PGVECTOR_METRIC, 'cosine'), // cosine | l2 | ip
        ],
        // 自定义别名示例：
        // 'milvus_prod' => ['driver' => 'milvus', 'uri' => '...'],
    ],
],
```

默认别名由 `neuron_ai.php` → `default_vector_store` 决定（stub 为 `file`）；`.env` 中 `RAG_VECTOR_STORE` 可覆盖。业务指定其它别名：

```php
$factory->make('product_kb', storeAlias: 'milvus_prod');
// 或节点配置：'vectorStore' => 'milvus_prod'
```

### 入库与检索

```php
use Swoolefy\Support\Neuron\Embedding\EmbeddingFactory;
use Swoolefy\Support\Neuron\NeuronAiConfig;
use Swoolefy\Support\Neuron\NeuronAiVectorStoreName;
use Swoolefy\Support\Rag\Factory\RagFactory;
use Swoolefy\Support\Rag\Factory\VectorStoreFactory;
use Swoolefy\Support\Rag\Retrieval\RetrievalService;

$config = NeuronAiConfig::fromArray([
    'rag' => [
        'default_vector_store' => NeuronAiVectorStoreName::FILE,
        'default_top_k' => 5,
        'vector_stores' => [
            NeuronAiVectorStoreName::FILE => ['path' => '/tmp/swoolefy_rag'],
        ],
    ],
]);
$rag = new RagFactory(new VectorStoreFactory($config), new EmbeddingFactory());

$rag->ingestionPipeline()->ingestTexts('product_kb', [
    'Swoolefy is a PHP coroutine framework.',
], tenantId: 'tenant_a');

$hits = (new RetrievalService($rag))->retrieve('product_kb', 'coroutine framework', 3, tenantId: 'tenant_a');
// 指定别名：$rag->vectorStore('product_kb', storeAlias: 'milvus_prod');
```

**Embedding**：生产须配置 API Key；`allow_fake_embeddings=false` 时无 Key 会 fail-fast。单测 / 本地可 `NEURON_ALLOW_FAKE_EMBEDDINGS=1`。

**向量库别名**：`VectorStoreFactory::make()` 对未在 `rag.vector_stores` 声明的 alias **抛错**（不再静默回退）。

### 工作流节点

```php
// 入库（可选 vectorStore 指定别名，缺省用 default_vector_store）
new RagIngestNode('ingest', [
    'knowledgeBase' => 'product_kb',
    'sourceKey' => 'documents', // state 中的文本列表
    'tenantIdKey' => 'tenantId', // 默认从 state.tenantId 读取；也可直接配置 tenantId
    // 'vectorStore' => 'milvus_prod',
], $pipeline);

// RagIngestNode 默认同步入库；rag.ingestion.mode=queue 时提交 RagIngestJob 给业务队列
new RagRetrieveNode('retrieve', [
    'knowledgeBase' => 'product_kb',
    'queryKey' => 'query',
    'outputKey' => 'retrievedDocs',
    'tenantIdKey' => 'tenantId',
    // 'vectorStore' => 'milvus_prod',
], $retrievalService);
```

生产建议在工作流启动 input 中显式带 `tenantId`，让 `RagIngestNode` / `RagRetrieveNode` 在 CLI、AsyncTask、子工作流等没有 HTTP Header 的场景也能稳定隔离租户。`RetrievalToolFactory::make()` 同样支持 `tenantId` 参数：

```php
$tool = $retrievalToolFactory->make('product_kb', topK: 5, tenantId: 'tenant_a');
```

### 大批量入库：队列模式

`RagIngestDispatcher` 支持 `sync | queue` 两种模式：

| 模式 | 行为 |
|------|------|
| `sync` | 默认行为，当前进程内直接 `embedDocuments()` 并写 VectorStore |
| `queue` | 将标准 `RagIngestJob` 交给配置的 producer；后台 consumer 再调用 `IngestionPipeline` |

配置示例：

```php
'rag' => [
    'ingestion' => [
        'mode' => env('RAG_INGEST_MODE', 'sync'),
        'queue' => [
            'producer' => [
                'class' => App\Queue\RagIngestProducer::class,
                'method' => 'push',
            ],
            'consumer' => [
                'class' => App\Queue\RagIngestConsumer::class,
                'method' => 'handle',
            ],
        ],
    ],
],
```

producer 负责把 Job 写入 Redis Queue / Kafka / RabbitMQ / DB Job 等任意队列：

```php
use Swoolefy\Support\Rag\Ingestion\RagIngestJob;

final class RagIngestProducer
{
    public function push(RagIngestJob $job): void
    {
        // 示例：$queue->push('rag_ingest', $job->toArray());
    }
}
```

consumer 负责从队列恢复 Job，并复用 Support 的入库管线：

```php
use Swoolefy\Support\Rag\Ingestion\IngestResult;
use Swoolefy\Support\Rag\Ingestion\IngestionPipeline;
use Swoolefy\Support\Rag\Ingestion\RagIngestJob;

final class RagIngestConsumer
{
    public function handle(RagIngestJob $job, IngestionPipeline $pipeline): IngestResult
    {
        return $pipeline->ingestTexts(
            $job->knowledgeBase,
            $job->texts,
            storeAlias: $job->vectorStore,
            tenantId: $job->tenantId,
        );
    }
}
```

生产大文件 / 大批量数据不建议把全文直接塞进队列。推荐在 `RagIngestJob::sourceRef` 中放 DB id、文件路径或 OSS key，consumer 拉取源数据后分批调用 `IngestionPipeline`。

### 离线 CLI

```bash
php src/Support/Rag/Console/ingest_documents.php --kb=product_kb --text="..." --tenant-id=tenant_a
# 或环境变量 NEURON_TENANT_ID=tenant_a
```

---

## 环境变量（.env）

常量定义见 [`NeuronAiRagEnv.php`](../Neuron/NeuronAiRagEnv.php)；完整说明亦见 [`Neuron/README.md`](../Neuron/README.md#rag-环境变量env)。在 `APP_PATH/.env` 配置，经 `env()` 读取；**优先级**：`.env` > `Config/neuron_ai.php` > 下表默认值。

### 全局 RAG

| 变量名 | 说明 | 未配置时的默认值 |
|--------|------|------------------|
| `RAG_VECTOR_STORE` | 默认向量库**别名**（须与 `rag.vector_stores` 的 key 一致；节点 / Factory 可用 `storeAlias` 覆盖） | `file`（来自 `neuron_ai.php` 的 `default_vector_store`） |
| `RAG_FILE_STORE_PATH` | **file** 驱动根目录；实际路径 `{path}/{tenantId}_{knowledgeBase}/` | `/tmp/swoolefy_rag` |
| `RAG_DEFAULT_TOP_K` | 相似度检索默认 TopK | `5` |
| `RAG_EMBEDDING_MODEL` | Embedding 模型名（`EmbeddingFactory`） | `text-embedding-3-small` |
| `RAG_EMBEDDING_DIMENSION` | Embedding 向量维度；须与各 `vector_stores.*.dimension` 一致 | `1536` |
| `NEURON_ALLOW_FAKE_EMBEDDINGS` | 无 API Key 时允许 FakeEmbeddings（`1`/`true`）；**生产须 false** | `false` |
| `RAG_REQUIRE_TENANT_ISOLATION` | 强制多租户：知识库 `{tenantId}_{kb}`；无 tenant 时 fail-fast；**生产须 true** | `true` |
| `NEURON_TENANT_ID` | CLI 入库 / 脚本场景的 tenant（等同 HTTP `x-tenant-id`；亦可用 `--tenant-id=`） | 空 |
| `RAG_INGEST_MODE` | 入库模式：`sync` 或 `queue` | `sync` |
| `RAG_INGEST_PRODUCER_CLASS` | 队列模式 producer 类名，方法接收 `RagIngestJob` | 空 |
| `RAG_INGEST_PRODUCER_METHOD` | producer 方法名 | `push` |
| `RAG_INGEST_CONSUMER_CLASS` | 队列 consumer 类名，方法接收 `RagIngestJob, IngestionPipeline` | 空 |
| `RAG_INGEST_CONSUMER_METHOD` | consumer 方法名 | `handle` |

### Meilisearch

| 变量名 | 说明 | 未配置时的默认值 |
|--------|------|------------------|
| `MEILISEARCH_HOST` | HTTP 地址 | `http://localhost:7700` |
| `MEILISEARCH_KEY` | API Key | 空（使用前须配置） |
| `MEILISEARCH_EMBEDDER` | 内置 embedder 名称 | `default` |
| `MEILISEARCH_DIMENSION` | 向量维度 | `1536` |

### PHPVector

| 变量名 | 说明 | 未配置时的默认值 |
|--------|------|------------------|
| `RAG_PHPVECTOR_PATH` | HNSW 存储根目录 `{path}/{tenantId}_{knowledgeBase}/` | `/tmp/swoolefy_phpvector` |

### MariaDB VECTOR

| 变量名 | 说明 | 未配置时的默认值 |
|--------|------|------------------|
| `RAG_MARIADB_COMPONENT` | 数据库组件别名（`Config/component/database.php`） | `db` |
| `RAG_MARIADB_TABLE_NAME` | 表名前缀；实际 `{table_name}_{tenantId}_{knowledgeBase}` | `rag_documents` |

### PostgreSQL pgvector

| 变量名 | 说明 | 未配置时的默认值 |
|--------|------|------------------|
| `RAG_PGVECTOR_COMPONENT` | PostgreSQL 数据库组件别名（`Config/component/database.php`） | `pg` |
| `RAG_PGVECTOR_TABLE_NAME` | 表名前缀；实际 `{table_name}_{tenantId}_{knowledgeBase}` | `rag_documents` |
| `RAG_PGVECTOR_DIMENSION` | pgvector 向量维度；须与 `RAG_EMBEDDING_DIMENSION` 一致 | `1536` |
| `RAG_PGVECTOR_METRIC` | 距离度量：`cosine`、`l2`、`ip` | `cosine` |

`PgVectorStore` 运行期不会自动创建 pgvector 扩展、表或索引。上线前须由迁移脚本 / DBA 预先执行 `CREATE EXTENSION vector`、创建 `{table_name}_{tenantId}_{knowledgeBase}` 表和 HNSW 索引，避免业务请求承担 DDL 成本。

### Pinecone

| 变量名 | 说明 | 未配置时的默认值 |
|--------|------|------------------|
| `PINECONE_API_KEY` | API Key | 空（使用前须配置） |
| `PINECONE_INDEX_URL` | 全局 Index URL | 空（使用前须配置） |
| `PINECONE_API_VERSION` | API 版本 | `2025-04` |

### Qdrant

| 变量名 | 说明 | 未配置时的默认值 |
|--------|------|------------------|
| `QDRANT_BASE_URL` | REST 根地址 | `http://localhost:6333` |
| `QDRANT_API_KEY` | API Key（视部署可选） | 空 |
| `QDRANT_DIMENSION` | Collection 向量维度 | `1536` |

### Milvus

| 变量名 | 说明 | 未配置时的默认值 |
|--------|------|------------------|
| `MILVUS_URI` | HTTP 端点（阿里云：`http://c-xxxx.milvus.aliyuncs.com:19530`） | `http://localhost:19530` |
| `MILVUS_USER` | 用户名（常与 password 配对） | 空 |
| `MILVUS_PASSWORD` | 密码 | 空 |
| `MILVUS_TOKEN` | JWT / Token；与 user+password 二选一 | 空 |
| `MILVUS_DB_NAME` | 数据库名 | `default` |
| `MILVUS_DIMENSION` | Collection 维度；须与 `RAG_EMBEDDING_DIMENSION` 一致 | `1536` |

### .env 示例

```env
RAG_VECTOR_STORE=file
RAG_FILE_STORE_PATH=/data/rag
RAG_EMBEDDING_MODEL=text-embedding-3-small
RAG_EMBEDDING_DIMENSION=1536
RAG_REQUIRE_TENANT_ISOLATION=1

# Milvus 生产
# RAG_VECTOR_STORE=milvus
# MILVUS_URI=http://c-xxxx.milvus.aliyuncs.com:19530
# MILVUS_USER=root
# MILVUS_PASSWORD=****
# MILVUS_DIMENSION=1536

# PostgreSQL pgvector
# RAG_VECTOR_STORE=pgvector
# RAG_PGVECTOR_COMPONENT=pg
# RAG_PGVECTOR_TABLE_NAME=rag_documents
# RAG_PGVECTOR_DIMENSION=1536
# RAG_PGVECTOR_METRIC=cosine
```

---

## 运行测试

```bash
composer test:rag
composer test:phase-a
# 或
php src/Support/Rag/Tests/RagModuleTest.php
```
