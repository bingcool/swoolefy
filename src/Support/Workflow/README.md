# Workflow 工作流引擎

Swoolefy 内置的 **DAG 工作流引擎**，用于编排业务节点、AI 决策分支、多 Agent 并行、RAG/MCP 工具调用与人机协同（HITL）。已实现 **Phase 1–4** 及 **生产加固（Phase A/B）**：HITL API 鉴权、resume CAS、多版本 Registry、节点默认超时、启动期健康检查等。

- 架构设计：[swoolefyAI.md](../../../docs/swoolefyAI.md)
- 快速接入：[docs/AI-WORKFLOW.md](../../../docs/AI-WORKFLOW.md)

---

## 目录结构

```
Workflow/
├── Workflow.php                 # Facade：define → compile → start / resume / cancel
├── WorkflowBootstrap.php        # 演示/单测协程单例装配
├── WorkflowComponentFactory.php # 生产装配（workflow.php + Redis RunStore）
├── WorkflowConfig.php           # workflow.php 解析
├── WorkflowRegistry.php         # workflowId + version 多版本注册表
├── WorkflowHitlAuth.php         # HITL API 鉴权（resume / cancel / pause/tasks）
├── Definition/                  # 声明层（纯 DAG，无 I/O）
├── Engine/                      # 运行时：Engine、Scheduler、RunStore、Saga
├── State/WorkflowState.php
├── Node/                        # AbstractNode、PauseNode、SubWorkflowNode…
├── Condition/                   # Symfony EL、JsonLogic、Callable、Composite
├── Plugin/                      # Retry、Tracing、Metrics、OTel、Audit、限流、权限
├── Audit/
├── Exception/
└── Tests/                       # Phase1–4 + Integration 回归
```

关联模块（各含 README + Tests）：

| 模块 | 路径 | 测试 |
|------|------|------|
| AI 节点 / 流式 | [`Support/AI/`](../AI/README.md) | `composer test:ai` |
| Agent 路由 | [`Support/Agent/`](../Agent/README.md) | `composer test:agent` |
| Neuron LLM / 记忆 | [`Support/Neuron/`](../Neuron/README.md) | `composer test:neuron` |
| RAG | [`Support/Rag/`](../Rag/README.md) | `composer test:rag` |
| MCP | [`Support/Mcp/`](../Mcp/README.md) | `composer test:mcp` |
| HTTP 示例 | `Test/Module/Workflow/`、`Test/Module/Order/` 等 | |

全部 Support 模块：`composer test:support`

---

## 核心原理：三层分离

```
WorkflowDefinition  ──compile()──►  CompiledWorkflow  ──start()──►  WorkflowRun
   （纯声明）                          （只读拓扑）                    （运行时快照）
```

| 层 | 职责 |
|----|------|
| **Definition** | `addNode` / `addEdge` / `addConditionalEdges` |
| **Compiler** | 环检测、入口校验、条件边冲突检查 |
| **Engine** | 节点生命周期、`DagScheduler` 路由、`RunStore` 持久化 |

原则：Definition 不含 I/O；CompiledWorkflow 可缓存；Engine 不直接依赖 neuron-ai（通过 AI 节点注入）。

---

## 快速上手

### Facade（脚本 / 单测）

```php
use Swoolefy\Support\Workflow\Workflow;
use Swoolefy\Support\Workflow\WorkflowBootstrap;
use Test\Module\Order\Workflow\OrderProcessingWorkflow;

WorkflowBootstrap::reset();
$engine = WorkflowBootstrap::engine();

$runId = Workflow::fromDefinition(OrderProcessingWorkflow::definition())
    ->compile()
    ->start(['orderId' => 10001, 'sessionId' => 'sess-abc'], $engine);

$run = $engine->getRun($runId);
```

### 生产装配

```php
use Swoolefy\Support\Workflow\WorkflowComponentFactory;
use Swoolefy\Support\Workflow\WorkflowRegistry;

$registry = new WorkflowRegistry();
$registry->register('order_processing', fn () => OrderProcessingWorkflow::definition());

$engine   = WorkflowComponentFactory::engine($registry);
$compiler = WorkflowComponentFactory::compiler();
$compiled = $compiler->compile($registry->definition('order_processing'));
$runId    = $engine->start($compiled, $input);
```

配置模板：

- `Config/workflow.php`（模版 `src/Stubs/workflow.conf.stub.php`，`create` 命令自动复制）
- `Config/neuron_ai.php`（模版 `src/Stubs/neuron_ai.conf.stub.php`；RAG / MCP / Neuron，见 `Support/Neuron/NeuronAiConfig.php`）

---

## 条件边

同一源节点 outgoing 解析顺序：

1. `addConditionalEdges` — 按声明顺序，**首个 true 获胜**
2. `addEdge` — 固定无条件边
3. `null` — Run 完成

**Symfony EL（默认）** — 变量：`data`、`nodeOutputs`、`feedback`、`state`

```php
EdgeCondition::when("data['decision']['approved'] == true")
```

**JSON Logic** — 设置 `condition_evaluator: jsonlogic` 或 `WORKFLOW_CONDITION_EVALUATOR=jsonlogic`

```php
EdgeCondition::fromJsonLogic(['>=' => [['var' => 'data.score'], 80]])
```

**Callable**

```php
EdgeCondition::fromCallable(fn (WorkflowState $s) => (bool) $s->get('ok'))
```

---

## HITL（PauseNode）

```php
$definition
    ->addNode('legal_review', new PauseNode('legal_review', [
        'assignee' => 'legal-team',
        'title'    => '合同审批',
    ]))
    ->addConditionalEdges('legal_review', [
        'approve_path' => EdgeCondition::when("data['feedback']['approved'] == true"),
        'reject_path'  => EdgeCondition::when("data['feedback']['approved'] == false"),
    ]);

$engine->resume($runId, ['approved' => true]);
$tasks = $engine->listPauseTasks('legal-team');  // RunStore 需 PauseTaskQueryableInterface
```

### HITL API 鉴权（Phase A）

`workflow.php` → `workflow.hitl`（模版见 `src/Stubs/workflow.conf.stub.php`）：

| 配置项 | 环境变量 | 说明 |
|--------|----------|------|
| `auth_enabled` | `WORKFLOW_HITL_AUTH_ENABLED` | 启用后 resume / cancel / pause/tasks 须鉴权 |
| `api_key` | `WORKFLOW_HITL_API_KEY` | 共享密钥，Header `X-Workflow-Api-Key` 或 Body `apiKey` |
| `role_header` | `WORKFLOW_HITL_ROLE_HEADER` | 角色 Header，默认 `X-Workflow-Role` |
| `allowed_roles` | — | 允许的角色列表（如 `operator`、`admin`） |
| `require_assignee_match` | `WORKFLOW_HITL_REQUIRE_ASSIGNEE_MATCH` | resume 时 `actor` 须匹配任务 assignee（`admin` 除外） |

HTTP 示例见 `Test/Module/Workflow/README.md`。实现：`WorkflowHitlAuth`、`WorkflowController`。

### resume 并发安全（Phase A）

`DbRunStore` / `RedisRunStore` / `InMemoryRunStore` 实现 `saveIfStatus()`；`WorkflowEngine::resume()` 仅在 Run 仍为 `WAITING` 时 CAS 更新，避免重复 resume 竞态。

---

## 多版本 Registry（Phase B）

```php
$registry->register('order_processing', fn () => OrderProcessingWorkflow::definition()); // latest = 2.0.0
$registry->registerVersion('order_processing', '1.0.0', fn () => OrderProcessingWorkflow::definitionV1());

$compiled = $registry->compiled('order_processing');           // latest
$compiled = $registry->compiled('order_processing', '1.0.0'); // 指定版本
```

Run 快照持久化 `workflowId` + `version`；`WorkflowRunSnapshot::hydrate()` 会校验版本与 Registry 一致，防止 resume 时拓扑漂移。

---

## 节点超时（Phase B）

`workflow.default_node_timeout_seconds`（默认 120，env `WORKFLOW_DEFAULT_NODE_TIMEOUT`）作为引擎全局默认。节点实现 `ConfigurableTimeoutNodeInterface`（如 `AINode`、`AgentParallelNode`）可单独覆盖；返回 `0` 表示使用全局默认。

---

## 子工作流（SubWorkflowNode）

```php
$runner = WorkflowComponentFactory::subWorkflowRunner($registry);

$definition->addNode('call_child', new SubWorkflowNode('call_child', [
    'workflowId' => 'order_processing',
    'inputKey'   => 'subWorkflowInput',
    'outputKey'  => 'subWorkflowOutput',
], $runner, $registry));
```

`SubWorkflowRunner` 同步嵌套执行子 Run，输出合并到父 `state.data`。

---

## Run 存储

配置见 `Config/workflow.php`（模版 `src/Stubs/workflow.conf.stub.php`）：

```php
use Swoolefy\Support\Workflow\WorkflowRunStoreName;

'default_run_store' => WorkflowRunStoreName::DB,
'run_stores' => [
    WorkflowRunStoreName::MEMORY => [],
    WorkflowRunStoreName::REDIS => ['component' => 'redis', 'prefix' => 'workflow:run:', 'ttl' => 86400],
    WorkflowRunStoreName::DB => ['component' => 'db', 'table' => 'workflow_runs'],
],
```

| 驱动 | 类 | 场景 |
|------|-----|------|
| `memory` | `InMemoryRunStore` | 单测、单 Worker 演示（不跨进程） |
| `redis` | `RedisRunStore` | 生产低延迟：跨 Worker resume / HITL |
| `db` | `DbRunStore` | 生产可查询：跨 Worker、按 status/assignee 索引、易备份审计 |

- Redis：`WorkflowRedisResolver` → `cache.php` 组件
- DB：`WorkflowPdoResolver` → `database.php` 组件；须预执行 `Schema/workflow_runs.sql`
- `DbRunStore` 使用事务 UPSERT + `saveIfStatus` CAS，死锁自动重试
- HTTP 入口使用 `WorkflowService::engine()` / `WorkflowComponentFactory`，保证与配置一致

### 启动期健康检查（Phase B）

部署前调用 `ProductionHealthCheck::run()`（或 `check()` 收集错误列表），校验向量库别名、Embedding 凭证、`default_node_timeout_seconds`、DB RunStore 表可达性、出站 URL 白名单等。

```bash
php -r "require 'vendor/autoload.php'; Swoolefy\Support\ProductionHealthCheck::run();"
```

---

## Plugin 与 EventBus

| 机制 | 用途 |
|------|------|
| **PluginManager** | 引擎内横切：Retry、Tracing、Metrics、OTel、Audit、RateLimit、Permission |
| **EventDispatcher** | 对外 SSE/WebSocket：`token`、`edge.route`、`pause`、`complete` |

钩子：`run.start` / `run.complete` / `node.before` / `node.after` / `node.fail` / `pause` / `resume`

环境开关示例：

```bash
WORKFLOW_OTEL_ENABLED=1
WORKFLOW_AUDIT_ENABLED=1
WORKFLOW_RATE_LIMIT_ENABLED=1
WORKFLOW_PERMISSION_ENABLED=1
WORKFLOW_MAX_CONCURRENT_RUNS=50
WORKFLOW_ALLOWED_ROLES=admin,operator
```

---

## Saga 补偿（Phase 4）

工作流 `metadata(['saga' => true])` 且节点 FAILED 时，`SagaCoordinator` 按逆序调用已执行节点的 `compensate()`。示例：`order_saga`（`Test/Module/Order/`）。

---

## HTTP API（Test 演示）

```
POST /api/v1/workflow/run
GET  /api/v1/workflow/run/status?runId=
POST /api/v1/workflow/run/resume
GET  /api/v1/workflow/pause/tasks?assignee=
GET  /api/v1/workflow/run/events?runId=     # SSE
POST /api/v1/agent/chat
GET  /api/v1/mcp/servers
GET  /api/v1/mcp/servers/{id}/tools?tenantId=
```

控制器：`Test/Module/Workflow/Controller/WorkflowController.php`、`Test/Module/Agent/Controller/AgentChatController.php`。

流式：`stream=true` 或 `Accept: text/event-stream`，经 `StreamBridge` 推送。

---

## 示例工作流

| workflowId | 说明 |
|------------|------|
| `order_processing` | AI 决策三分支 + 人工复核 |
| `order_saga` | 预占→支付→失败补偿 |
| `multi_agent_research` | `AgentParallelNode` + RuleRouter |
| `mcp_research` | MCP + LLMRouter |
| `contract_review` | HITL 法务环 |
| `knowledge_qa` | RAG 检索 + AINode |

---

## RAG / MCP / Agent（跨模块）

| 能力 | 入口 |
|------|------|
| 向量库 | `VectorStoreFactory::fromEnv()` — `file` / `meilisearch` |
| 入库 | `IngestionPipeline`、`RagIngestNode`、CLI `Support/Rag/Console/ingest_documents.php` |
| 检索 | `RagRetrieveNode`、`RAGNode`、`RetrievalToolFactory` |
| MCP | `McpFactory`、`McpProcessRunner`、`InMemoryMcpServerConfigRepository` |
| Agent 路由 | Static / Rule / LLM / Weighted / CostAware / **RoundRobin** |

Neuron：`NeuronFactory` + Agent::`chatHistory()`（`ChatHistoryFactory`）+ `SwooleHttpClientAdapter`。

---

## 协程单例隔离

`WorkflowBootstrap` 通过 `Context` 缓存 Engine/Compiler，**禁止进程级 static**。单测调用 `WorkflowBootstrap::reset()`。

自定义 `PluginManager` 传入时始终新建 Engine，不写入 Context。

---

## 运行测试

```bash
composer test:workflow
composer test:phase-a    # HITL 鉴权、resume CAS
composer test:phase-b    # 多版本、超时、MCP 租户、健康检查
```

或：

```bash
php src/Support/Workflow/Tests/WorkflowPhase1Test.php   # 7 项
php src/Support/Workflow/Tests/WorkflowPhase2Test.php   # 6 项
php src/Support/Workflow/Tests/WorkflowPhase3Test.php   # 7 项
php src/Support/Workflow/Tests/WorkflowPhase4Test.php   # 10 项
php src/Support/Workflow/Tests/WorkflowHitlAuthTest.php # HITL / CAS
php src/Support/Workflow/Tests/WorkflowIntegrationTest.php  # SubWorkflow / JsonLogic / RoundRobin / CLI
php src/Support/Tests/PhaseAProductionTest.php
php src/Support/Tests/PhaseBProductionTest.php
```

---

## Phase 5（规划中）

检索缓存、MCP 审计与 rate limit 深化、Composer 拆包等 — 见 `docs/swoolefyAI.md` §15。
