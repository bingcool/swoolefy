# RAG（检索增强生成）

文档入库、向量存储、相似度检索与工作流节点。入库与检索共用同一 `RagFactory`，保证 **Embedding 与 VectorStore 配置一致**。

- 架构设计：[swoolefyAI.md](../../../docs/swoolefyAI.md) §4.10
- 配置：`Test/Config/neuron_ai.php` → `rag`
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

**知识库隔离**：`knowledgeBase` 经 sanitize 后映射为子目录、indexUid、namespace、collection 或表名后缀。

| 驱动 (`NeuronAiVectorStoreName`) | 隔离方式 |
|----------------------------------|----------|
| `file` | `{path}/{kb}/` |
| `meilisearch` | indexUid |
| `phpvector` | `{path}/{kb}` |
| `mariadb` | `{table}_{kb}` |
| `pinecone` | namespace |
| `qdrant` / `milvus` | collection |

---

## 快速上手

### 配置

```php
// neuron_ai.php
'rag' => [
    'vector_store' => NeuronAiVectorStoreName::FILE, // 或 MEILISEARCH / MILVUS ...
    'file_store_path' => '/tmp/swoolefy_rag',
    'default_top_k' => 5,
    'embedding_model' => 'text-embedding-3-small',
    NeuronAiVectorStoreName::MILVUS => [
        'uri' => env(NeuronAiRagEnv::MILVUS_URI, 'http://localhost:19530'),
        // ...
    ],
],
```

切换驱动：`RAG_VECTOR_STORE=milvus`（环境变量优先）。

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
        'vector_store' => NeuronAiVectorStoreName::FILE,
        'file_store_path' => '/tmp/swoolefy_rag',
        'default_top_k' => 5,
    ],
]);
$rag = new RagFactory(new VectorStoreFactory($config), new EmbeddingFactory());

$rag->ingestionPipeline()->ingestTexts('product_kb', [
    'Swoolefy is a PHP coroutine framework.',
]);

$hits = (new RetrievalService($rag))->retrieve('product_kb', 'coroutine framework', 3);
```

无 `OPENAI_API_KEY` 时使用 `FakeEmbeddingsProvider`（维度 64），适合本地与单测。

### 工作流节点

```php
// 入库
new RagIngestNode('ingest', [
    'knowledgeBase' => 'product_kb',
    'sourceKey' => 'documents', // state 中的文本列表
], $pipeline);

// 检索
new RagRetrieveNode('retrieve', [
    'knowledgeBase' => 'product_kb',
    'queryKey' => 'query',
    'outputKey' => 'retrievedDocs',
], $retrievalService);
```

### 离线 CLI

```bash
php src/Support/Rag/Console/ingest_documents.php --kb=product_kb --file=./docs.txt
```

---

## 环境变量（节选）

| 变量 | 说明 |
|------|------|
| `RAG_VECTOR_STORE` | 驱动名 |
| `RAG_FILE_STORE_PATH` | file 根目录 |
| `RAG_DEFAULT_TOP_K` | 默认 TopK |
| `RAG_EMBEDDING_MODEL` | Embedding 模型名 |
| `MEILISEARCH_*` / `MILVUS_*` / `PINECONE_*` / `QDRANT_*` | 见 `NeuronAiRagEnv` |

---

## 运行测试

```bash
composer test:rag
# 或
php src/Support/Rag/Tests/RagModuleTest.php
```
