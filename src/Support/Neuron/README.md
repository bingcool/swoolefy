# Neuron 基础设施（LLM / 记忆 / Embedding / HTTP）

封装 [Neuron AI](https://docs.neuron-ai.dev/) 在 Swoolefy 中的装配：Provider 工厂、会话记忆、协程 HTTP、Embedding、出站 URL 校验。

- 配置：`Config/neuron_ai.php`（模版 `src/Stubs/neuron_ai.conf.stub.php`，`create` 命令自动复制）
- 架构设计：[swoolefyAI.md](../../../docs/swoolefyAI.md) §4.7
- Chat History 文档：[Neuron Chat History](https://docs.neuron-ai.dev/agent/chat-history-and-memory)

---

## 目录结构

```
Neuron/
├── NeuronFactory.php            # boot/create Agent：Provider + MCP（不强制 Memory）
├── NeuronProviderFactory.php
├── Schema/
│   ├── chat_history.sql         # SQLChatHistory 热存储（使用前须建表）
│   └── chat_messages.sql        # SqlChatHistoryArchive 冷归档（使用前须建表）
├── Memory/
│   ├── ChatHistoryFactory.php       # Agent::chatHistory() 选用后端
│   ├── SqlChatHistory.php           # SQL 热存储（tenant + thread 隔离）
│   ├── ChatHistoryPdoResolver.php   # 组件容器解析 PDO
│   ├── RedisChatHistory.php         # Redis 热存储
│   ├── HotChatHistoryInterface.php
│   └── SqlChatHistoryArchive.php    # 可选：逐条冷归档
├── Http/
├── Embedding/
└── Tests/
```

---

## 核心原理

```
业务 Agent
  protected function chatHistory(): ChatHistoryInterface
      └─ ChatHistoryFactory::inMemory() | sql() | redis() | file()

NeuronFactory::create / boot
  ├─ applyProvider（节点 provider 别名或 default；baseUri 经 OutboundUrlGuard）
  ├─ setChatHistory（仅当 nodeConfig['chatHistory'] 显式传入）
  └─ addTool（mcpServers + tenantId / FrameworkContext）
```

会话记忆由 **Agent 自身** 在 `chatHistory()` 中声明。Redis / SQL 后端连接失败会写入 `SupportLog`。

### Embedding（Phase A）

`rag.embedding_dimension` 须与各 `vector_stores.*.dimension` 一致。生产环境须配置 API Key；未配置且 `allow_fake_embeddings=false` 时 `EmbeddingFactory::make()` **fail-fast** 抛错。

本地 / 单测可设 `NEURON_ALLOW_FAKE_EMBEDDINGS=1` 或配置 `allow_fake_embeddings: true`（使用 FakeEmbeddingsProvider，维度与 `embedding_dimension` 对齐）。

---

## RAG 环境变量（.env）

常量定义见 [`NeuronAiRagEnv.php`](NeuronAiRagEnv.php)。在 `APP_PATH/.env` 中配置，经 `env()` 读取；**优先级**：`.env` 环境变量 > `Config/neuron_ai.php` > 下表默认值。

运行时也可直接调用静态方法，例如 `NeuronAiRagEnv::milvusUri()`。

### 全局 RAG

| 变量名 | 说明 | 未配置时的默认值 |
|--------|------|------------------|
| `RAG_VECTOR_STORE` | 默认向量库**别名**（须与 `neuron_ai.php` → `rag.vector_stores` 的 key 一致；可被节点 / Factory 的 `storeAlias` 覆盖） | `file`（来自 `neuron_ai.php` 的 `default_vector_store`） |
| `RAG_FILE_STORE_PATH` | **file** 驱动根目录；实际存储路径为 `{path}/{tenantId}_{knowledgeBase}/` | `/tmp/swoolefy_rag` |
| `RAG_DEFAULT_TOP_K` | 相似度检索默认返回条数 | `5` |
| `RAG_EMBEDDING_MODEL` | OpenAI Embedding 模型名（`EmbeddingFactory` 使用） | `text-embedding-3-small` |
| `RAG_EMBEDDING_DIMENSION` | Embedding 输出向量维度；须与各 `vector_stores.*.dimension` 一致 | `1536` |
| `NEURON_ALLOW_FAKE_EMBEDDINGS` | 无 API Key 时是否允许 FakeEmbeddings（`1`/`true` 开启）；**生产须为 false** | `false` |
| `RAG_REQUIRE_TENANT_ISOLATION` | 是否强制多租户隔离：RAG 知识库前缀 `{tenantId}_{kb}`、Redis ChatHistory key 含 tenant；无 tenant 时 fail-fast（`1`/`true` 开启）；**生产须为 true** | `true` |
| `NEURON_TENANT_ID` | CLI 入库 / 脚本场景的 tenant（等同 HTTP `x-tenant-id`；RAG CLI 亦支持 `--tenant-id=`） | 空 |

### Meilisearch（`vector_stores.meilisearch`）

| 变量名 | 说明 | 未配置时的默认值 |
|--------|------|------------------|
| `MEILISEARCH_HOST` | Meilisearch HTTP 地址 | `http://localhost:7700` |
| `MEILISEARCH_KEY` | API Key（Master Key） | 空（使用该驱动前须配置） |
| `MEILISEARCH_EMBEDDER` | Meilisearch 内置 embedder 名称 | `default` |
| `MEILISEARCH_DIMENSION` | 向量维度 | `1536` |

知识库名映射为 Meilisearch `indexUid`（经 `TenantScope` 加 tenant 前缀）。

### PHPVector（`vector_stores.phpvector`）

| 变量名 | 说明 | 未配置时的默认值 |
|--------|------|------------------|
| `RAG_PHPVECTOR_PATH` | 纯 PHP HNSW 存储根目录；路径为 `{path}/{tenantId}_{knowledgeBase}/` | `/tmp/swoolefy_phpvector` |

需 `composer require neuron-core/php-vector`。

### MariaDB VECTOR（`vector_stores.mariadb`）

| 变量名 | 说明 | 未配置时的默认值 |
|--------|------|------------------|
| `RAG_MARIADB_COMPONENT` | 数据库组件别名（对应 `Config/component/database.php`） | `db` |
| `RAG_MARIADB_TABLE_NAME` | 表名前缀；实际表名为 `{table_name}_{tenantId}_{knowledgeBase}` | `rag_documents` |

需 MariaDB ≥ 11.7 且支持 VECTOR 类型。

### Pinecone（`vector_stores.pinecone`）

| 变量名 | 说明 | 未配置时的默认值 |
|--------|------|------------------|
| `PINECONE_API_KEY` | Pinecone API Key | 空（使用该驱动前须配置） |
| `PINECONE_INDEX_URL` | 全局 Index URL | 空（使用该驱动前须配置） |
| `PINECONE_API_VERSION` | Pinecone API 版本 | `2025-04` |

知识库名映射为 Pinecone **namespace**（经 tenant 前缀隔离）。

### Qdrant（`vector_stores.qdrant`）

| 变量名 | 说明 | 未配置时的默认值 |
|--------|------|------------------|
| `QDRANT_BASE_URL` | Qdrant REST 根地址 | `http://localhost:6333` |
| `QDRANT_API_KEY` | API Key（可选，视部署而定） | 空 |
| `QDRANT_DIMENSION` | Collection 向量维度 | `1536` |

Collection URL 为 `{base_url}/collections/{tenantId}_{knowledgeBase}/`。

### Milvus（`vector_stores.milvus`）

| 变量名 | 说明 | 未配置时的默认值 |
|--------|------|------------------|
| `MILVUS_URI` | Milvus HTTP 端点（阿里云示例：`http://c-xxxx.milvus.aliyuncs.com:19530`） | `http://localhost:19530` |
| `MILVUS_USER` | 用户名（常与 `MILVUS_PASSWORD` 配对，阿里云常见） | 空 |
| `MILVUS_PASSWORD` | 密码 | 空 |
| `MILVUS_TOKEN` | JWT / API Token；与 user+password **二选一**（同时配置时 client 优先 token） | 空 |
| `MILVUS_DB_NAME` | Milvus 数据库名 | `default` |
| `MILVUS_DIMENSION` | Collection 向量维度；须与 `RAG_EMBEDDING_DIMENSION` 一致 | `1536` |

知识库名映射为独立 **Collection**（经 tenant 前缀隔离）；首次写入自动建表。

### .env 示例

```env
# 全局
RAG_VECTOR_STORE=file
RAG_FILE_STORE_PATH=/data/rag
RAG_EMBEDDING_MODEL=text-embedding-3-small
RAG_EMBEDDING_DIMENSION=1536
RAG_REQUIRE_TENANT_ISOLATION=1

# 生产：须配置真实 Embedding Key，且勿开 Fake
# NEURON_ALLOW_FAKE_EMBEDDINGS=0

# Milvus 生产示例
# RAG_VECTOR_STORE=milvus
# MILVUS_URI=http://c-xxxx.milvus.aliyuncs.com:19530
# MILVUS_USER=root
# MILVUS_PASSWORD=****
# MILVUS_DIMENSION=1536
```

CLI 入库脚本在无 HTTP 上下文时可额外设置 `NEURON_TENANT_ID`（或 `--tenant-id=`），与 `x-tenant-id` 请求头等效。

---

## 业务 Agent 示例

### InMemory（单次请求，无持久化）

```php
final class EphemeralChatAgent extends Agent
{
    protected function chatHistory(): ChatHistoryInterface
    {
        return ChatHistoryFactory::inMemory(50000);
    }
}
```

### SQL 持久化多轮（SqlChatHistory）

**使用前必须先执行** `Schema/chat_history.sql` 创建 `chat_history` 表（含 `tenant_id` / `user_id` / 软删 `deleted_at`）。

```php
final class ChatAgent extends Agent
{
    public function __construct(
        private readonly string $threadId,
        private readonly \PDO $pdo,
        private readonly ?string $tenantId = null, // 缺省读 x-tenant-id
        private readonly ?string $userId = null,   // 缺省读 x-user-id
    ) {
        parent::__construct();
    }

    protected function chatHistory(): ChatHistoryInterface
    {
        return ChatHistoryFactory::sql(
            threadId: $this->threadId,
            pdo: $this->pdo,
            tenantId: $this->tenantId,
            userId: $this->userId,
        );
    }
}

// Controller
$pdo = ChatHistoryPdoResolver::resolve('db');
$agent = $neuronFactory->boot(new ChatAgent($threadId, $pdo), [
    'provider' => 'deepseek',
]);
```

建表脚本：`src/Support/Neuron/Schema/chat_history.sql`

```sql
-- 见 Schema/chat_history.sql（uk_tenant_thread + messages JSON）
```

### SQL 冷归档（逐条消息，可选）

**使用前必须先执行** `Schema/chat_messages.sql` 创建 `chat_messages` 表。

```php
$archive = ChatHistoryFactory::archive($pdo, tenantId: 'tenant_a', userId: 'user_1');
$archive->archiveMessage($threadId, 'user', 'Hello');
$messages = $archive->listMessages($threadId, 50);
```

建表脚本：`src/Support/Neuron/Schema/chat_messages.sql`

### Redis

```php
protected function chatHistory(): ChatHistoryInterface
{
    return ChatHistoryFactory::redis($this->threadId, $redisConnection);
}
```

---

## NeuronFactory

```php
// 无参 Agent
$agent = $factory->create(ChatAgent::class, $state, ['provider' => 'openai']);

// 带构造参数的 Agent（自行决定 chatHistory）
$agent = $factory->boot(new ChatAgent($threadId, $pdo), ['provider' => 'deepseek']);
```

节点 MCP 工具加载时，`NeuronFactory` 会将 `tenantId`（节点 config 或 `FrameworkContext::getTenantId()`）传给 `McpFactory`。

---

## 安全与启动检查（Phase B）

`neuron_ai.php` → `security`：

| 配置 | 说明 |
|------|------|
| `outbound_url_allowlist` | LLM Provider `baseUri` 与 MCP `url` 的 host 后缀白名单 |
| `allow_private_networks` | 是否允许指向私网 / loopback（默认 false） |

部署前调用 `Swoolefy\Support\ProductionHealthCheck::run()` 校验 Embedding、向量库别名、出站 URL 等。该检查也覆盖 Workflow 生产安全项：`SWOOLEFY_ENV=prd` 时要求 HITL 鉴权开启、`WORKFLOW_HITL_API_KEY` 已配置，并拦截过短的 Redis RunStore TTL。

---

## 运行测试

```bash
composer test:neuron
composer test:phase-a
composer test:phase-b
```
