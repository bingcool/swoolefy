# Rag 模块 — 检索增强生成演示

本模块提供完整的 RAG HTTP 演示：配置探查、种子入库、自定义入库、相似度检索、抽取式/Agent 问答，以及 `retrieve → answer` 工作流。

配置来源：`Test/Config/neuron_ai.php` → `rag.default_vector_store` / `rag.vector_stores`。

默认 API 前缀：`/api`。下文假设服务监听 `http://127.0.0.1:9501`。

## 目录结构

```
Rag/
├── Agent/DemoKnowledgeRag.php   # RAG Agent（可选 LLM）
├── Controller/RagController.php # HTTP 入口
├── Workflow/RagQaWorkflow.php   # retrieve → answer
├── RagService.php               # 入库 / 检索 / 问答服务
└── README.md
```

## API 一览

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/api/v1/rag/config` | 当前 RAG 配置摘要 |
| GET | `/api/v1/rag/stores` | 已声明向量库别名 |
| POST | `/api/v1/rag/seed` | 写入演示语料 |
| POST | `/api/v1/rag/ingest` | 自定义文本入库 |
| POST | `/api/v1/rag/retrieve` | 相似度检索 |
| POST | `/api/v1/rag/ask` | 检索增强问答 |
| POST | `/api/v1/rag/workflow/qa` | 工作流问答 |

公共可选字段：

| 字段 | 说明 |
|------|------|
| `knowledgeBase` | 知识库名，默认 `demo_kb` |
| `vectorStore` | `vector_stores` 别名；缺省用 `default_vector_store` |
| `topK` | 检索条数；缺省用配置 `default_top_k` |
| `query` / `question` | 查询文本（retrieve / ask / workflow 必填） |

---

## 推荐演示顺序

### 1. 查看配置

```bash
curl -s 'http://127.0.0.1:9501/api/v1/rag/config' | jq .
curl -s 'http://127.0.0.1:9501/api/v1/rag/stores' | jq .
```

### 2. 写入演示语料

```bash
curl -s -X POST 'http://127.0.0.1:9501/api/v1/rag/seed' \
  -H 'Content-Type: application/json' \
  -d '{
    "knowledgeBase": "demo_kb",
    "vectorStore": "file"
  }' | jq .
```

预期：`documentCount` 为演示文档条数。

### 3. 自定义入库

```bash
curl -s -X POST 'http://127.0.0.1:9501/api/v1/rag/ingest' \
  -H 'Content-Type: application/json' \
  -d '{
    "knowledgeBase": "demo_kb",
    "texts": [
      "Refund policy allows returns within 30 days of purchase.",
      "Premium support includes 24x7 on-call for enterprise plans."
    ]
  }' | jq .
```

### 4. 相似度检索

```bash
curl -s -X POST 'http://127.0.0.1:9501/api/v1/rag/retrieve' \
  -H 'Content-Type: application/json' \
  -d '{
    "query": "What is RAG in swoolefy?",
    "knowledgeBase": "demo_kb",
    "topK": 3
  }' | jq .
```

响应含 `hits[]`：`content` / `score` / `metadata`。

### 5. 抽取式问答（默认，无需 API Key）

```bash
curl -s -X POST 'http://127.0.0.1:9501/api/v1/rag/ask' \
  -H 'Content-Type: application/json' \
  -d '{
    "query": "How does vector_stores alias work?",
    "knowledgeBase": "demo_kb",
    "topK": 3,
    "useAgent": false
  }' | jq .
```

### 6. Agent 问答（可选 LLM）

```bash
curl -s -X POST 'http://127.0.0.1:9501/api/v1/rag/ask' \
  -H 'Content-Type: application/json' \
  -d '{
    "query": "What vector store drivers are supported?",
    "useAgent": true
  }' | jq .
```

无 `OPENAI_API_KEY` 时 `DemoKnowledgeRag` 使用 Fake 回退文案。

### 7. 工作流问答

```bash
curl -s -X POST 'http://127.0.0.1:9501/api/v1/rag/workflow/qa' \
  -H 'Content-Type: application/json' \
  -d '{
    "question": "What is Saga compensation?",
    "knowledgeBase": "demo_kb",
    "topK": 3
  }' | jq .
```

流程：`retrieve`（RagRetrieveNode）→ `answer`（抽取式 ClosureNode）。

也可通过通用 Workflow API：

```bash
# 需先 seed
curl -s -X POST 'http://127.0.0.1:9501/api/v1/workflow/run' \
  -H 'Content-Type: application/json' \
  -d '{
    "workflowId": "rag_qa",
    "question": "What is RAG in swoolefy?"
  }' | jq .
```

---

## 指定向量库别名

`vectorStore` 必须是 `neuron_ai.php` → `rag.vector_stores` 中已声明的 key：

```bash
curl -s -X POST 'http://127.0.0.1:9501/api/v1/rag/retrieve' \
  -H 'Content-Type: application/json' \
  -d '{
    "query": "milvus collection isolation",
    "vectorStore": "milvus"
  }' | jq .
```

未声明别名会返回 400，并提示 `GET /api/v1/rag/stores`。

---

## 代码用法

```php
use Test\Module\Rag\RagService;

$rag = RagService::instance();
$rag->seed('demo_kb');
$hits = $rag->retrieve('demo_kb', 'What is RAG?', topK: 3);
$answer = $rag->ask('demo_kb', 'What is RAG?', useAgent: false);
```

---

## 相关文档

- 框架 RAG 实现：`src/Support/Rag/README.md`
- 配置：`Test/Config/neuron_ai.php`
- Knowledge 工作流：`Test/Module/Knowledge/Workflow/KnowledgeQaWorkflow.php`
- 通用工作流：`Test/Module/Workflow/README.md`
)
