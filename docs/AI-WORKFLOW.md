# Swoolefy AI / Workflow 快速指南

本文档面向**生产接入**：如何在 Swoole 协程应用中运行 DAG 工作流、Agent 路由、RAG 与 MCP。完整架构见 [SwoolefyAI.md](SwoolefyAI.md)。

各模块 README（目录结构、原理、示例、测试命令）：

| 模块 | README |
|------|--------|
| Workflow | [src/Support/Workflow/README.md](../src/Support/Workflow/README.md) |
| Agent | [src/Support/Agent/README.md](../src/Support/Agent/README.md) |
| AI | [src/Support/AI/README.md](../src/Support/AI/README.md) |
| Neuron | [src/Support/Neuron/README.md](../src/Support/Neuron/README.md) |
| Rag | [src/Support/Rag/README.md](../src/Support/Rag/README.md) |
| Mcp | [src/Support/Mcp/README.md](../src/Support/Mcp/README.md) |
| CapabilityCenter | [src/Support/CapabilityCenter/README.md](../src/Support/CapabilityCenter/README.md) |
| DocumentOcr | [src/Support/DocumentOcr/README.md](../src/Support/DocumentOcr/README.md) |

---

## 1. 能力概览（Phase 1–4 + 生产加固 A–D + 增量）

| 领域 | 能力 | 主要命名空间 |
|------|------|-------------|
| 工作流引擎 | Definition / Compiler / Engine；条件边；HITL 鉴权；resume CAS；多版本 Registry；节点超时；Saga | `Swoolefy\Support\Workflow` |
| AI 节点 | `AINodeBuilder`、`StructuredOutputNode`、流式 SSE/WebSocket、`AgentParallelNode` | `Swoolefy\Support\AI` |
| Agent | Static / Rule / LLM / Weighted / CostAware / RoundRobin 路由 | `Swoolefy\Support\Agent` |
| Neuron | LLM 工厂、Middleware、Provider Fallback、Redis/SQL 记忆、Embedding、出站 URL | `Swoolefy\Support\Neuron` |
| RAG | 多向量库、sync/queue 入库、租户隔离 | `Swoolefy\Support\Rag` |
| MCP | HTTP/SSE、DB 仓储、stdio 守卫、URL 白名单 | `Swoolefy\Support\Mcp` |
| CapabilityCenter | Tool Top-K + pinned（默认关闭） | `Swoolefy\Support\CapabilityCenter` |
| DocumentOcr | Pandoc + DeepSeek OCR（图片/PDF）→ Markdown | `Swoolefy\Support\DocumentOcr` |
| 运维 | `ProductionHealthCheck` | `Swoolefy\Support` |
| 插件 | Retry、Tracing、Metrics、OTel、Audit、RateLimit、Permission | `Support/Workflow/Plugin` |

---

## 2. 配置

将模板复制到应用目录（`php cli.php create AppName` 会自动从 `src/Stubs/` 复制到 `Config/`）：

```bash
cp src/Stubs/workflow.conf.stub.php App/Config/workflow.php
cp src/Stubs/neuron_ai.conf.stub.php App/Config/neuron_ai.php
```

**workflow.php** — RunStore、条件求值器、HITL 鉴权、节点超时：

```php
return [
    'workflow' => [
        'default_run_store' => 'db',   // memory | redis | db
        'condition_evaluator' => 'symfony',
        'default_node_timeout_seconds' => 120,
        'hitl' => [
            'auth_enabled' => true,
            'api_key' => env('WORKFLOW_HITL_API_KEY'),
            'allowed_roles' => ['operator', 'admin'],
            'require_assignee_match' => true,
        ],
        'run_stores' => [ /* memory / redis / db */ ],
    ],
];
```

**neuron_ai.php** — RAG、MCP、安全、Neuron Provider：

```php
use Swoolefy\Support\Neuron\NeuronAiModelEnv;
use Swoolefy\Support\Neuron\NeuronAiProviderName;

'neuron' => [
    'default_provider' => NeuronAiProviderName::ANTHROPIC,
    'ai_model_providers' => [
        NeuronAiProviderName::OPENAILIKE => [
            'provider' => \NeuronAI\Providers\OpenAILike::class,
            'baseUri' => env(NeuronAiModelEnv::OPENAILIKE_BASE_URI, 'https://api.together.xyz/v1'),
            'key' => env(NeuronAiModelEnv::OPENAILIKE_API_KEY),
            'model' => env(NeuronAiModelEnv::OPENAILIKE_MODEL),
            'parameters' => [],
            'strict_response' => false,
        ],
    ],
],
```

除 `provider`（FQCN）外，其余键与 Provider 构造函数参数一致。Agent 未实现 `provider()` 时由 `NeuronFactory` 注入 `default_provider` 对应配置。

常用环境变量：

| 变量 | 说明 |
|------|------|
| `OPENAI_API_KEY` | LLM / Embedding |
| `NEURON_DEFAULT_PROVIDER` | 默认 Provider 别名（`NeuronAiProviderName::*`） |
| `WORKFLOW_RUN_STORE` | `memory` / `redis` / `db` |
| `WORKFLOW_DEFAULT_NODE_TIMEOUT` | 节点默认超时秒数（默认 120） |
| `WORKFLOW_HITL_AUTH_ENABLED` | HITL API 鉴权（`1` 启用） |
| `WORKFLOW_HITL_API_KEY` | HITL 共享密钥 |
| `WORKFLOW_HITL_REQUIRE_ASSIGNEE_MATCH` | resume 时校验 assignee（默认 `1`） |
| `WORKFLOW_REDIS_COMPONENT` | Redis 组件别名（如 `redis`、`predis`，见 `component/cache.php`） |
| `WORKFLOW_REDIS_PREFIX` | Run 快照 key 前缀 |
| `WORKFLOW_REDIS_TTL` | Run 快照 TTL（秒） |
| `WORKFLOW_CONDITION_EVALUATOR` | `symfony` / `jsonlogic` |
| `RAG_VECTOR_STORE` | `file` / `meilisearch` / `phpvector` / `mariadb` / `pinecone` / `qdrant` / `milvus` |
| `MEILISEARCH_HOST` | Meilisearch 地址 |
| `RAG_PHPVECTOR_PATH` | PHPVector 数据目录（需 `neuron-core/php-vector`） |
| `RAG_MARIADB_COMPONENT` | MariaDB 组件别名（如 `db`） |
| `RAG_MARIADB_TABLE_NAME` | MariaDB 向量表名前缀 |
| `PINECONE_API_KEY` | Pinecone API Key |
| `PINECONE_INDEX_URL` | Pinecone Index URL |
| `QDRANT_BASE_URL` | Qdrant HTTP 地址 |
| `QDRANT_API_KEY` | Qdrant API Key |
| `MILVUS_URI` | Milvus HTTP 地址（如 `http://c-xxx.milvus.aliyuncs.com:19530`） |
| `MILVUS_USER` | Milvus 用户名 |
| `MILVUS_PASSWORD` | Milvus 密码 |
| `MILVUS_TOKEN` | Milvus JWT Token（与 user/password 二选一） |
| `MILVUS_DB_NAME` | Milvus 数据库名（默认 `default`） |
| `MILVUS_DIMENSION` | Milvus 向量维度（默认 1536） |
| `MCP_MAX_LOCAL_PROCESSES` | 本地 MCP 并发上限 |
| `MCP_ALLOW_STDIO` | 是否允许 stdio MCP（生产默认 `0`） |
| `NEURON_ALLOW_FAKE_EMBEDDINGS` | 本地 / 单测 Fake Embedding |
| `RAG_EMBEDDING_DIMENSION` | Embedding 维度（默认 1536） |
| `WORKFLOW_OTEL_ENABLED=1` | OpenTelemetry 插件 |
| `WORKFLOW_AUDIT_ENABLED=1` | 审计日志 |
| `WORKFLOW_RATE_LIMIT_ENABLED=1` | Run 并发限流 |
| `WORKFLOW_PERMISSION_ENABLED=1` | 角色权限校验 |

---

## 3. 生产装配

演示环境可用 `WorkflowBootstrap`；**生产推荐** `WorkflowComponentFactory` + `WorkflowRegistry`：

```php
use Swoolefy\Support\Workflow\WorkflowComponentFactory;
use Swoolefy\Support\Workflow\WorkflowRegistry;
use Test\Module\Order\Workflow\OrderProcessingWorkflow;

$registry = new WorkflowRegistry();
$registry->register('order_processing', static fn () => OrderProcessingWorkflow::definition());

$engine = WorkflowComponentFactory::engine($registry);
$compiler = WorkflowComponentFactory::compiler();
$subRunner = WorkflowComponentFactory::subWorkflowRunner($registry);

$compiled = $compiler->compile($registry->definition('order_processing'));
$runId = $engine->start($compiled, ['orderId' => 10001, 'sessionId' => 's1']);
```

部署前建议：

```php
\Swoolefy\Support\ProductionHealthCheck::run();
```

`run_store: redis` 时通过 `Application::getApp()->get(component)->getObject()` 获取 `RedisConnection`（连接信息在 `component/cache.php` 配置），Run 快照跨 Worker 持久化。

Test 项目参考：`Test/Module/Workflow/WorkflowService.php`（注册全部示例工作流）。

---

## 4. HTTP API（Test 演示）

前缀 `/api`（见 `Test/Router/Common/Api.php`）：

| 方法 | 路径 | 说明 |
|------|------|------|
| POST | `/v1/workflow/run` | 启动工作流 `{ workflowId, input, stream? }` |
| GET | `/v1/workflow/run/status` | `?runId=` 查询状态 |
| POST | `/v1/workflow/run/resume` | HITL 恢复 `{ runId, feedback, actor? }` + 鉴权 Header |
| POST | `/v1/workflow/run/cancel` | 取消 Run（HITL 鉴权） |
| GET | `/v1/workflow/pause/tasks` | `?assignee=` 待办列表（HITL 鉴权） |
| GET | `/v1/workflow/run/events` | SSE 流式事件 |
| POST | `/v1/agent/chat` | Agent 对话 `{ message, sessionId, userId }` |
| GET | `/v1/mcp/servers` | MCP 服务列表（`?tenantId=`） |
| GET | `/v1/mcp/servers/tools` | MCP 工具发现（`?server_id=&tenantId=`） |

### 启动示例

```bash
# 启动 Test 应用
php cli.php start Test

# 订单 AI 决策
curl -s -X POST http://127.0.0.1:9501/api/v1/workflow/run \
  -H 'Content-Type: application/json' \
  -d '{"workflowId":"order_processing","input":{"orderId":10001,"sessionId":"demo"}}'

# Agent 对话（无 Key 时 Echo 模式）
curl -s -X POST http://127.0.0.1:9501/api/v1/agent/chat \
  -H 'Content-Type: application/json' \
  -d '{"message":"hello","sessionId":"s1","userId":"u1"}'

# 知识库问答（需先入库）
php vendor/bingcool/swoolefy/src/Support/Rag/Console/ingest_documents.php \
  --kb=product_kb --text="产品门框标准宽度为 900mm"
```

---

## 5. 示例工作流

| workflowId | 模块路径 | 说明 |
|------------|----------|------|
| `order_processing` | `Test/Module/Order/` | AI 三分支 + 人工复核 |
| `order_saga` | `Test/Module/Order/` | Saga 补偿 |
| `multi_agent_research` | `Test/Module/Research/` | 多 Agent 并行 |
| `mcp_research` | `Test/Module/Research/` | MCP + LLM 路由 |
| `contract_review` | `Test/Module/Contract/` | HITL 法务审批 |
| `knowledge_qa` | `Test/Module/Knowledge/` | RAG 问答 |

---

## 6. 条件边

**Symfony EL（默认）**：

```php
EdgeCondition::when("data['decision']['approved'] == true and data['decision']['confidence'] >= 0.8")
```

**JSON Logic**（`condition_evaluator: jsonlogic`）：

```php
EdgeCondition::fromJsonLogic(['>=' => [['var' => 'data.score'], 80]])
```

**Callable**：

```php
EdgeCondition::fromCallable(fn (WorkflowState $s) => $s->get('flag') === true)
```

---

## 7. 子工作流

```php
use Swoolefy\Support\Workflow\Node\SubWorkflowNode;

$definition->addNode('nested', new SubWorkflowNode('nested', [
    'workflowId' => 'order_processing',
    'inputKey' => 'subWorkflowInput',
    'outputKey' => 'subWorkflowOutput',
], $subRunner, $registry));
```

---

## 8. RAG 入库 CLI

```bash
# 目录批量入库
php src/Support/Rag/Console/ingest_documents.php --kb=product_kb --path=/data/docs

# 单条文本
php src/Support/Rag/Console/ingest_documents.php --kb=product_kb --text="规格说明..."
```

需要 `OPENAI_API_KEY`（或 `NEURON_ALLOW_FAKE_EMBEDDINGS=1` 用于本地）。向量库 alias 须在 `rag.vector_stores` 声明。

---

## 9. 测试

```bash
# 全部 Support 模块
composer test:support

# 生产加固回归
composer test:phase-a
composer test:phase-b

# 分模块
composer test:agent
composer test:ai
composer test:mcp
composer test:neuron
composer test:rag
composer test:workflow
```

---

## 10. Phase 5（规划中）

检索缓存、MCP 审计与限流深化、Composer 拆包等见 `SwoolefyAI.md` §15 Phase 5。
