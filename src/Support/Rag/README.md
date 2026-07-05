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
│   └── VectorStoreFactory.php   # file / meilisearch / phpvector / mariadb / pinecone / qdrant / milvus
├── Store/
│   ├── MeilisearchConfig.php
│   └── MilvusVectorStore.php    # 自定义：阿里云 / 自建 Milvus
├── Resolver/RagPdoResolver.php  # MariaDB PDO 组件解析
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

**知识库隔离**：`knowledgeBase` 经 sanitize 后映射为子目录、indexUid、namespace、collection 或表名后缀。开启 `RAG_REQUIRE_TENANT_ISOLATION` 时物理名为 `{tenantId}_{kb}`（tenant 来自 `x-tenant-id` 或显式参数）。

| 驱动 (`NeuronAiVectorStoreName`) | 隔离方式 |
|----------------------------------|----------|
| `file` | `{path}/{tenantId}_{kb}/` |
| `meilisearch` | indexUid = `{tenantId}_{kb}` |
| `phpvector` | `{path}/{tenantId}_{kb}/` |
| `mariadb` | `{table}_{tenantId}_{kb}` |
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
]);

$hits = (new RetrievalService($rag))->retrieve('product_kb', 'coroutine framework', 3);
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
    // 'vectorStore' => 'milvus_prod',
], $pipeline);

// RagIngestNode 仅同步入库（RAG_INGEST_ASYNC 已移除）
new RagRetrieveNode('retrieve', [
    'knowledgeBase' => 'product_kb',
    'queryKey' => 'query',
    'outputKey' => 'retrievedDocs',
    // 'vectorStore' => 'milvus_prod',
], $retrievalService);
```

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
```

---

## 运行测试

```bash
composer test:rag
composer test:phase-a
# 或
php src/Support/Rag/Tests/RagModuleTest.php
```
