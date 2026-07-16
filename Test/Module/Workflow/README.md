# Workflow 模块 — 通用工作流 API

本模块是 **统一入口**：通过 `workflowId` 启动已注册的示例工作流，并提供列表、DAG 探查、状态查询、HITL 恢复、取消与 SSE。

Run 持久化由 `Test/Config/workflow.php` 控制（`default_run_store` + `run_stores`）：

| 驱动 | 说明 |
|------|------|
| `memory` | 默认演示；不跨 Worker |
| `redis` | 跨 Worker，低延迟（`cache.php` 组件） |
| `db` | 跨 Worker，可查询 HITL（`database.php` 组件，表见 `Schema/workflow_runs.sql`） |

生产将 `default_run_store` 设为 `db` 或 `redis`；DB 场景须先执行 `Schema/workflow_runs.sql`。

与业务专用 Demo 的关系（**模块本地装配 + 联邦目录**）：

| 入口 | 路径前缀 | Runtime |
|------|----------|---------|
| **本模块** | `/api/v1/workflow/*` | `engineFor` / `engineForRun` 路由到拥有方 Registry→RunStore |
| Order Demo | `/api/v1/order/workflow/*` | `OrderWorkflowService`（definition 真源） |
| Research Demo | `/api/v1/research/workflow/*` | `ResearchWorkflowService` |
| Outdoor Demo | `/api/v1/outdoor/workflow/*` | `OutdoorWorkflowService` |
| Rag Demo | `/api/v1/rag/*` | `RagWorkflowService` |
| Contract | （统一 API / 无专用 Demo） | `ContractWorkflowService` |
| Knowledge | （统一 API / 无专用 Demo） | `KnowledgeWorkflowService` |

约定：**谁启动谁查询**；同一 `WorkflowRegistry` 复用同一 RunStore（见 `WorkflowComponentFactory`）。
模块回归：`composer test:module-workflows`。

默认 API 前缀：`/api`。下文假设服务监听 `http://127.0.0.1:9501`。

## 目录结构

```
Workflow/
├── Controller/WorkflowController.php   # 通用 HTTP API（联邦路由）
├── WorkflowService.php                 # 联邦目录 + engineFor*（definition 全部委托各模块）
├── Tests/WorkflowFederationTest.php
└── README.md
```

## 已注册工作流

| workflowId | 模块 | 说明 | 典型入参 |
|------------|------|------|----------|
| `order_processing` | Order | AI 风控三分支 | `orderId`, `amount` |
| `order_saga` | Order | Saga 补偿演示 | `orderId`, `amount` |
| `multi_agent_research` | Research | coding + finance 并行 | `query` |
| `outdoor_cycling` | Outdoor | 天气+路线+备车并行 → 骑行/留家 | `destination`, `weatherHint` |
| `mcp_research` | Research | MCP 研究 → notify/archive | `query`（含 urgent 走 notify） |
| `contract_review` | Contract | 法务 HITL 审批 | `contractBrief` |
| `knowledge_qa` | Knowledge | RAG 检索问答 | `question` |

完整目录也可通过接口获取（含 `demoInput`）。

---

## API 一览

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/api/v1/workflow/list` | 列出已注册工作流 |
| GET | `/api/v1/workflow/describe?workflowId=` | DAG 详情（节点 / 边 / 条件） |
| POST | `/api/v1/workflow/run` | 启动 Run |
| GET | `/api/v1/workflow/run/status?runId=` | 查询状态（HITL 鉴权；默认脱敏摘要） |
| POST | `/api/v1/workflow/run/resume` | HITL 恢复 |
| POST | `/api/v1/workflow/run/cancel` | 取消 Run |
| GET | `/api/v1/workflow/pause/tasks?assignee=` | 暂停任务列表 |
| GET | `/api/v1/workflow/run/events?runId=` | SSE 推送当前状态（HITL 鉴权；默认脱敏摘要） |

### HITL / Status 鉴权

以下接口须携带 **API Key** 或 **角色**（满足其一即可）：`resume`、`cancel`、`pause/tasks`、`status`、`events`。

| 方式 | 字段 |
|------|------|
| Header | `X-Workflow-Api-Key: <api_key>` |
| Header | `X-Workflow-Role: operator`（或 `admin` 等 `allowed_roles`） |
| Body | `apiKey` / `role` |

**resume** 额外要求（`require_assignee_match=true` 时）：Body 提供 `actor` 或 `assignee`，须与暂停任务 assignee 一致；`admin` 角色可跨 assignee。

**pause/tasks**：非 admin 查询他人 assignee 会被拒绝；未传 `assignee` 时须提供 `actor`。

**status / events**：默认只返回安全摘要，不包含 `data`、`nodeOutputs`、`agentOutputs`、完整 `error`。只有 `X-Workflow-Role: admin` 且 `detail=true` / `debug=true` 时才返回完整调试视图。

生产默认 `auth_enabled=true`；请配置强 `WORKFLOW_HITL_API_KEY`。若本地演示想关闭鉴权，可显式设 `WORKFLOW_HITL_AUTH_ENABLED=0`。

---

## curl 示例

### 1. 列出工作流

```bash
curl -s 'http://127.0.0.1:9501/api/v1/workflow/list' | jq .
```

### 2. 查看 DAG

```bash
curl -s 'http://127.0.0.1:9501/api/v1/workflow/describe?workflowId=order_processing' | jq .
```

### 3. 启动订单处理

`input` 对象与顶层平铺字段均可（平铺会合并进 input，不覆盖已有键）：

```bash
curl -s -X POST 'http://127.0.0.1:9501/api/v1/workflow/run' \
  -H 'Content-Type: application/json' \
  -d '{
    "workflowId": "order_processing",
    "input": {
      "orderId": "ORD-WF-10001",
      "userId": "u1",
      "amount": 199
    }
  }' | jq .
```

简写：

```bash
curl -s -X POST 'http://127.0.0.1:9501/api/v1/workflow/run' \
  -H 'Content-Type: application/json' \
  -d '{
    "workflowId": "order_processing",
    "orderId": "ORD-WF-10002",
    "amount": 88
  }' | jq .
```

### 4. 启动 Saga 补偿

```bash
curl -s -X POST 'http://127.0.0.1:9501/api/v1/workflow/run' \
  -H 'Content-Type: application/json' \
  -d '{
    "workflowId": "order_saga",
    "orderId": "ORD-WF-SAGA-1",
    "amount": 50
  }' | jq .
```

预期：`status` 为补偿相关终态，`data.compensatedNodes` 含 payment / reserve。

### 5. 多 Agent 研究

```bash
curl -s -X POST 'http://127.0.0.1:9501/api/v1/workflow/run' \
  -H 'Content-Type: application/json' \
  -d '{
    "workflowId": "multi_agent_research",
    "query": "Analyze swoolefy workflow design"
  }' | jq .
```

### 6. MCP 研究（紧急 → notify）

```bash
curl -s -X POST 'http://127.0.0.1:9501/api/v1/workflow/run' \
  -H 'Content-Type: application/json' \
  -d '{
    "workflowId": "mcp_research",
    "query": "urgent security patch review"
  }' | jq .
```

### 7. 合同 HITL（暂停 → 恢复）

启动后会进入 `waiting`：

```bash
curl -s -X POST 'http://127.0.0.1:9501/api/v1/workflow/run' \
  -H 'Content-Type: application/json' \
  -d '{
    "workflowId": "contract_review",
    "contractBrief": "SaaS annual subscription for Acme Corp"
  }' | jq .
```

查看暂停任务（生产环境须鉴权 Header）：

```bash
curl -s 'http://127.0.0.1:9501/api/v1/workflow/pause/tasks?assignee=legal-team' \
  -H 'X-Workflow-Api-Key: test-hitl-key' | jq .
```

法务通过（`actor` 须匹配 assignee `legal-team`，或使用 `admin` 角色）：

```bash
curl -s -X POST 'http://127.0.0.1:9501/api/v1/workflow/run/resume' \
  -H 'Content-Type: application/json' \
  -H 'X-Workflow-Api-Key: test-hitl-key' \
  -d '{
    "runId": "<runId>",
    "actor": "legal-team",
    "feedback": { "approved": true, "reason": "ok" }
  }' | jq .
```

驳回修订（会回到 legal_review 再次暂停）：

```bash
curl -s -X POST 'http://127.0.0.1:9501/api/v1/workflow/run/resume' \
  -H 'Content-Type: application/json' \
  -H 'X-Workflow-Role: admin' \
  -d '{
    "runId": "<runId>",
    "feedback": { "approved": false, "reason": "need clause update" }
  }' | jq .
```

### 8. 查询状态 / 取消

```bash
curl -s 'http://127.0.0.1:9501/api/v1/workflow/run/status?runId=<runId>' | jq .
curl -s 'http://127.0.0.1:9501/api/v1/workflow/run/status?runId=<runId>&detail=true' \
  -H 'X-Workflow-Role: admin' | jq .

curl -s -X POST 'http://127.0.0.1:9501/api/v1/workflow/run/cancel' \
  -H 'Content-Type: application/json' \
  -H 'X-Workflow-Api-Key: test-hitl-key' \
  -d '{"runId":"<runId>"}' | jq .
```

### 9. SSE 启动

```bash
curl -N -X POST 'http://127.0.0.1:9501/api/v1/workflow/run' \
  -H 'Content-Type: application/json' \
  -H 'Accept: text/event-stream' \
  -d '{
    "workflowId": "mcp_research",
    "query": "weekly notes",
    "stream": true
  }'
```

### 10. SSE 查看已有 Run

```bash
curl -N 'http://127.0.0.1:9501/api/v1/workflow/run/events?runId=<runId>' \
  -H 'X-Workflow-Api-Key: test-hitl-key'
```

---

## 响应字段（Run）

| 字段 | 说明 |
|------|------|
| `runId` | 运行实例 ID |
| `workflowId` / `version` | 定义标识 |
| `status` | `completed` / `failed` / `waiting` / `compensated` / `cancelled` 等 |
| `waiting` | 是否 HITL 等待中 |
| `currentNodeId` / `pauseNodeId` | 当前 / 暂停节点 |
| `executedNodeIds` | 已成功节点（Saga 补偿依据） |
| `hasError` | 是否存在失败信息 |
| `data` | 业务 state.data；仅 `admin + detail=true` 返回 |
| `nodeOutputs` / `agentOutputs` | 节点输出 / 多 Agent 输出；仅 `admin + detail=true` 返回 |
| `error` | 完整失败信息；仅 `admin + detail=true` 返回 |

---

## 代码用法

```php
use Swoolefy\Support\Workflow\WorkflowBootstrap;
use Test\Module\Workflow\WorkflowService;

// 目录
$catalog = WorkflowService::catalog();

// 详情
$detail = WorkflowService::describe('order_processing');

// 启动
$compiled = WorkflowService::registry()->compiled('order_saga');
$engine = WorkflowBootstrap::engine();
$runId = $engine->start($compiled, [
    'orderId' => 'ORD-1',
    'amount' => 50.0,
]);
$run = $engine->getRun($runId);
```

单测隔离：

```php
WorkflowService::reset();
```

---

## 相关文档

- Order 演示：`Test/Module/Order/README.md`
- Research 演示：`Test/Module/Research/README.md`
- 引擎与 Saga：`docs/SwoolefyAI.md`
- 注册表实现：`src/Support/Workflow/WorkflowRegistry.php`
)
