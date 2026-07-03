# Workflow 工作流引擎

Swoolefy 内置的 **DAG 工作流引擎**，用于编排业务节点、AI 决策分支、多 Agent 并行、RAG/MCP 工具调用与人机协同（HITL）。已实现 **Phase 1–4**（Phase 5 生产化增强见 `swoolefyAI.md`）。

- 架构设计：[swoolefyAI.md](../../../swoolefyAI.md)
- 快速接入：[docs/AI-WORKFLOW.md](../../../docs/AI-WORKFLOW.md)

---

## 目录结构

```
Workflow/
├── Workflow.php                 # Facade：define → compile → start / resume / cancel
├── WorkflowBootstrap.php        # 演示/单测协程单例装配
├── WorkflowComponentFactory.php # 生产装配（workflow.php + Redis RunStore）
├── WorkflowConfig.php           # workflow.php 解析
├── WorkflowRegistry.php         # workflowId → Definition 注册表
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

关联模块：

| 模块 | 路径 |
|------|------|
| AI 节点 / 流式 | `Support/AI/` |
| Agent 路由 | `Support/Agent/` |
| Neuron LLM / 记忆 | `Support/Neuron/` |
| RAG | `Support/Rag/` |
| MCP | `Support/Mcp/` |
| HTTP 示例 | `Test/Module/Workflow/`、`Test/Module/Order/` 等 |

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

- `Test/Config/workflow.php` → `APP_PATH/config/workflow.php`（引擎）
- `Test/Config/neuron_ai.php` → `APP_PATH/config/neuron_ai.php`（RAG / MCP / Neuron，见 `Support/Neuron/NeuronAiConfig.php`）

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

| 驱动 | 类 | 场景 |
|------|-----|------|
| `memory` | `InMemoryRunStore` | 单测、单 Worker 演示 |
| `redis` | `RedisRunStore` | 生产：引用 `cache.php` 组件别名，跨 Worker resume、HITL 任务列表 |

`RedisRunStore` 通过 `WorkflowRedisResolver` 从 `Application::getApp()->get('redis'|'predis')` 解析 `RedisConnection`。

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

Neuron：`NeuronFactory` + `MemoryFactory` + `SwooleHttpClientAdapter`（协程 HTTP）。

---

## 协程单例隔离

`WorkflowBootstrap` 通过 `Context` 缓存 Engine/Compiler，**禁止进程级 static**。单测调用 `WorkflowBootstrap::reset()`。

自定义 `PluginManager` 传入时始终新建 Engine，不写入 Context。

---

## 运行测试

```bash
composer test:workflow
```

或：

```bash
php src/Support/Workflow/Tests/WorkflowPhase1Test.php   # 7 项
php src/Support/Workflow/Tests/WorkflowPhase2Test.php   # 6 项
php src/Support/Workflow/Tests/WorkflowPhase3Test.php   # 7 项
php src/Support/Workflow/Tests/WorkflowPhase4Test.php   # 10 项
php src/Support/Workflow/Tests/WorkflowIntegrationTest.php  # SubWorkflow / JsonLogic / RoundRobin / CLI
```

---

## Phase 5（未实现）

RAG/MCP 多租户隔离、检索缓存、MCP 审计与 rate limit 深化、Composer 拆包等 — 见 `swoolefyAI.md` §15。
