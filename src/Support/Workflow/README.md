# Workflow 工作流引擎

Swoolefy 内置的 **DAG 工作流引擎**，用于编排业务节点、AI 决策分支、多 Agent 并行、RAG/MCP 工具调用与人机协同（HITL）。已实现 **Phase 1–4** 及 **生产加固（Phase A/B）**：HITL API 鉴权、resume CAS、多版本 Registry、节点默认超时、启动期健康检查等。

- 架构设计：[SwoolefyAI.md](../../../docs/SwoolefyAI.md)
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
use Swoolefy\Support\Neuron\NeuronFactory;
use Swoolefy\Support\Workflow\Workflow;
use Swoolefy\Support\Workflow\WorkflowBootstrap;
use Swoolefy\Support\Workflow\Tests\Fixtures\OrderProcessingFixtureWorkflow;

WorkflowBootstrap::reset();
$engine = WorkflowBootstrap::engine();

$runId = Workflow::fromDefinition(
    OrderProcessingFixtureWorkflow::definition(new NeuronFactory()),
)
    ->compile()
    ->start(['orderId' => 10001, 'sessionId' => 'sess-abc'], $engine);

$run = $engine->getRun($runId);
```

> 业务 Demo 工作流仍在 `Test/Module/*`；Support 单测夹具在 `src/Support/Workflow/Tests/Fixtures/`。

### 生产装配

```php
use Swoolefy\Support\Neuron\NeuronFactory;
use Swoolefy\Support\Workflow\WorkflowComponentFactory;
use Swoolefy\Support\Workflow\WorkflowRegistry;
use Swoolefy\Support\Workflow\Tests\Fixtures\OrderProcessingFixtureWorkflow;

$registry = new WorkflowRegistry();
$registry->register(
    'order_processing',
    fn () => OrderProcessingFixtureWorkflow::definition(new NeuronFactory()),
);

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

生产默认要求开启 HITL 鉴权，并配置强 `WORKFLOW_HITL_API_KEY`。`ProductionHealthCheck` 会在 `SWOOLEFY_ENV=prd` 时拦截 `auth_enabled=false` 或空 `api_key`，避免 `resume` / `cancel` / `pause/tasks` / `status` 误暴露。

HTTP 示例见 `Test/Module/Workflow/README.md`。实现：`WorkflowHitlAuth`、`WorkflowController`。

### Status 鉴权与脱敏（P0）

`GET /api/v1/workflow/run/status` 与 `GET /api/v1/workflow/run/events` 同样走 HITL 鉴权。状态接口默认只返回安全摘要：

- `runId`、`workflowId`、`version`、`status`、`waiting`
- `currentNodeId`、`pauseNodeId`、`lastRoutedEdge`、`executedNodeIds`
- `hasError`

默认不会返回 `state.data`、`nodeOutputs`、`agentOutputs`、完整 `error`，避免泄露业务输入、AI 输出、HITL feedback 或 MCP/RAG 中间结果。只有 `role=admin` 且显式 `detail=true` / `debug=true` 时，控制器才返回完整调试视图。

### resume 并发安全（Phase A）

`DbRunStore` / `RedisRunStore` / `InMemoryRunStore` 实现 `saveIfStatus()`；`WorkflowEngine::resume()` 仅在 Run 仍为 `WAITING` 时 CAS 更新，避免重复 resume 竞态。

---

## 多版本 Registry（Phase B）

```php
$registry->register('order_processing', fn () => OrderProcessingFixtureWorkflow::definition(new NeuronFactory())); // latest
$registry->registerVersion('order_processing', '1.0.0', fn () => OrderProcessingFixtureWorkflow::definition(new NeuronFactory()));

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
    WorkflowRunStoreName::REDIS => ['component' => 'redis', 'prefix' => 'workflow:run:', 'ttl' => 0],
    WorkflowRunStoreName::DB => ['component' => 'db', 'table' => 'workflow_runs'],
],
```

| 驱动 | 类 | 场景 |
|------|-----|------|
| `memory` | `InMemoryRunStore` | 单测、单 Worker 演示（不跨进程） |
| `redis` | `RedisRunStore` | 生产低延迟：跨 Worker resume / HITL |
| `db` | `DbRunStore` | 生产可查询：跨 Worker、按 status/assignee 索引、易备份审计 |

- Redis：`WorkflowRedisResolver` → `cache.php` 组件
- Redis `ttl=0` 表示不过期。生产 HITL 任务可能跨天/跨周审批，不建议设置短 TTL；`SWOOLEFY_ENV=prd` 时，`ProductionHealthCheck` 会拦截小于 7 天的 Redis RunStore TTL。
- DB：`WorkflowPdoResolver` → `database.php` 组件；**须预执行** `Schema/workflow_runs.sql` 建表（`DbRunStore` 不会自动建表）
- `run_id` 由 `WorkflowRunTime::generateRunId()` 生成，格式 `run_YYYYMMDD_{16位hex}`
- 表字段 `created_at` / `updated_at` 为 `DATETIME`；`deleted_at` 软删（查询默认过滤）
- `DbRunStore` 使用事务 UPSERT + `saveIfStatus` CAS，死锁自动重试
- HTTP 入口使用 `WorkflowService::engine()` / `WorkflowComponentFactory`，保证与配置一致

### 单测：DbRunStore + SQLite 内存库

生产 MySQL/MariaDB 须预执行 `Schema/workflow_runs.sql`；**单测**若用 `sqlite::memory:`，表结构由 `Tests/WorkflowRunsSchemaInstaller` 安装（仅支持 SQLite，与生产 SQL 字段对齐）：

```php
use PDO;
use Swoolefy\Support\Workflow\Engine\DbRunStore;
use Swoolefy\Support\Workflow\Tests\WorkflowRunsSchemaInstaller;
use Swoolefy\Support\Workflow\WorkflowRegistry;

$pdo = new PDO('sqlite::memory:');
WorkflowRunsSchemaInstaller::install($pdo);
$store = new DbRunStore($pdo, $registry, 'workflow_runs');
```

参考：`WorkflowRunStoreTest.php`、`WorkflowHitlAuthTest.php`、`WorkflowIntegrationTest.php` 中的 `testDbRunStore*`。

### 启动期健康检查（Phase B）

部署前调用 `ProductionHealthCheck::run()`（或 `check()` 收集错误列表），校验向量库别名、Embedding 凭证、`default_node_timeout_seconds`、DB RunStore 表可达性、出站 URL 白名单、生产 HITL 鉴权、Redis RunStore TTL 等。

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
GET  /api/v1/mcp/servers/tools?server_id=&tenantId=
```

控制器：`Test/Module/Workflow/Controller/WorkflowController.php`、`Test/Module/Agent/Controller/AgentChatController.php`。

流式：`stream=true` 或 `Accept: text/event-stream`，经 `StreamBridge` 推送。

状态查询：`status` / `events` 默认返回脱敏摘要；需携带 HITL API Key 或角色。完整 state 仅 `admin + detail=true` 返回。

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

或按类过滤：

```bash
./vendor/bin/phpunit --filter WorkflowPhase1Test   # 7 项
./vendor/bin/phpunit --filter WorkflowPhase2Test   # 6 项
./vendor/bin/phpunit --filter WorkflowPhase3Test   # 7 项
./vendor/bin/phpunit --filter WorkflowPhase4Test   # 10 项
./vendor/bin/phpunit --filter WorkflowHitlAuthTest # HITL / CAS
./vendor/bin/phpunit --filter WorkflowIntegrationTest  # SubWorkflow / JsonLogic / RoundRobin / CLI
./vendor/bin/phpunit --filter PhaseAProductionTest
./vendor/bin/phpunit --filter PhaseBProductionTest
```

---

## Phase 5（规划中）

检索缓存、MCP 审计与 rate limit 深化、Composer 拆包等 — 见 `docs/SwoolefyAI.md` §15。
