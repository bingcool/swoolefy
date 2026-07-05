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

默认别名：`RAG_VECTOR_STORE=milvus`（环境变量优先）。业务指定其它别名：

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
php src/Support/Rag/Console/ingest_documents.php --kb=product_kb --file=./docs.txt
```

---

## 环境变量（节选）

| 变量 | 说明 |
|------|------|
| `RAG_VECTOR_STORE` | 驱动名 |
| `RAG_FILE_STORE_PATH` | file 驱动 path（覆盖 vector_stores.file.path） |
| `RAG_DEFAULT_TOP_K` | 默认 TopK |
| `RAG_EMBEDDING_MODEL` | Embedding 模型名 |
| `RAG_EMBEDDING_DIMENSION` | Embedding 向量维度（默认 1536） |
| `NEURON_ALLOW_FAKE_EMBEDDINGS` | 单测 / 本地 Fake Embedding（`1` 启用） |
| `MEILISEARCH_*` / `MILVUS_*` / `PINECONE_*` / `QDRANT_*` | 见 `NeuronAiRagEnv` |

---

## 运行测试

```bash
composer test:rag
composer test:phase-a
# 或
php src/Support/Rag/Tests/RagModuleTest.php
```
