# Swoolefy AI / Workflow 快速指南

本文档面向**生产接入**：如何在 Swoole 协程应用中运行 DAG 工作流、Agent 路由、RAG 与 MCP。完整架构见 [swoolefyAI.md](../swoolefyAI.md)，模块细节见 [src/Support/Workflow/README.md](../src/Support/Workflow/README.md)。

---

## 1. 能力概览（Phase 1–4，Phase 5 除外）

| 领域 | 能力 | 主要命名空间 |
|------|------|-------------|
| 工作流引擎 | Definition / Compiler / Engine 三层分离；条件边；HITL；Saga | `Swoolefy\Support\Workflow` |
| AI 节点 | `AINodeBuilder`、`StructuredOutputNode`、流式 SSE/WebSocket | `Swoolefy\Support\AI` |
| Agent | Static / Rule / LLM / Weighted / CostAware / RoundRobin 路由 | `Swoolefy\Support\Agent` |
| Neuron | LLM 工厂、Redis 记忆、Swoole 协程 HTTP、Embedding | `Swoolefy\Support\Neuron` |
| RAG | 向量库（file / Meilisearch）、入库 Pipeline、检索 Tool | `Swoolefy\Support\Rag` |
| MCP | 远程 HTTP、本地 stdio 进程、多租户配置 | `Swoolefy\Support\Mcp` |
| 插件 | Retry、Tracing、Metrics、OTel、Audit、RateLimit、Permission | `Support/Workflow/Plugin` |

---

## 2. 配置

将模板复制到应用目录：

```bash
cp Test/Config/workflow.php App/config/workflow.php
cp Test/Config/neuron_ai.php App/config/neuron_ai.php
```

**workflow.php** — 引擎 RunStore、条件求值器：

```php
return [
    'workflow' => [
        'run_store' => 'memory',       // memory | redis
        'condition_evaluator' => 'symfony',
        'redis' => [
            'component' => 'redis',    // Config/component/cache.php 中的组件别名
            'prefix' => 'workflow:run:',
            'ttl' => 86400,
        ],
    ],
];
```

**neuron_ai.php** — RAG、MCP、Neuron HTTP：

```php
return [
    'rag' => ['vector_store' => 'file', ...],
    'mcp' => ['max_local_processes' => 2],
    'neuron' => ['http_client' => 'swoole'],
];
```

常用环境变量：

| 变量 | 说明 |
|------|------|
| `OPENAI_API_KEY` | LLM / Embedding |
| `WORKFLOW_RUN_STORE` | `memory` / `redis` |
| `WORKFLOW_REDIS_COMPONENT` | Redis 组件别名（如 `redis`、`predis`，见 `component/cache.php`） |
| `WORKFLOW_REDIS_PREFIX` | Run 快照 key 前缀 |
| `WORKFLOW_REDIS_TTL` | Run 快照 TTL（秒） |
| `WORKFLOW_CONDITION_EVALUATOR` | `symfony` / `jsonlogic` |
| `RAG_VECTOR_STORE` | `file` / `meilisearch` |
| `MEILISEARCH_HOST` | Meilisearch 地址 |
| `MCP_MAX_LOCAL_PROCESSES` | 本地 MCP 并发上限 |
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

`run_store: redis` 时通过 `Application::getApp()->get(component)->getObject()` 获取 `RedisConnection`（连接信息在 `component/cache.php` 配置），Run 快照跨 Worker 持久化。

Test 项目参考：`Test/Module/Workflow/WorkflowService.php`（注册全部示例工作流）。

---

## 4. HTTP API（Test 演示）

前缀 `/api`（见 `Test/Router/Common/Api.php`）：

| 方法 | 路径 | 说明 |
|------|------|------|
| POST | `/v1/workflow/run` | 启动工作流 `{ workflowId, input, stream? }` |
| GET | `/v1/workflow/run/status` | `?runId=` 查询状态 |
| POST | `/v1/workflow/run/resume` | HITL 恢复 `{ runId, feedback }` |
| GET | `/v1/workflow/pause/tasks` | `?assignee=` 待办列表 |
| GET | `/v1/workflow/run/events` | SSE 流式事件 |
| POST | `/v1/agent/chat` | Agent 对话 `{ message, sessionId, userId }` |
| GET | `/v1/mcp/servers` | MCP 服务列表 |
| GET | `/v1/mcp/servers/{id}/tools` | MCP 工具发现 |

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

需要 `OPENAI_API_KEY`（或项目配置的 Embedding 后端）。向量库默认 `file`（无需 Meilisearch）；生产可切 `meilisearch`。

---

## 9. 测试

```bash
composer test:workflow
# 或逐项：
php src/Support/Workflow/Tests/WorkflowPhase1Test.php
php src/Support/Workflow/Tests/WorkflowPhase2Test.php
php src/Support/Workflow/Tests/WorkflowPhase3Test.php
php src/Support/Workflow/Tests/WorkflowPhase4Test.php
php src/Support/Workflow/Tests/WorkflowIntegrationTest.php
```

---

## 10. Phase 5（暂未实现）

多租户 RAG/MCP 隔离、检索缓存、MCP 审计与限流深化、Composer 拆包等见 `swoolefyAI.md` §15 Phase 5。
