# Research 模块 — 工作流演示

本模块演示两类研究工作流：

1. **multi_agent_research**：多 Agent 并行（编码 + 财务）后汇总
2. **mcp_research**：声明 MCP 工具的研究节点 → 结构化摘要 → 按紧急度 notify / archive

演示入口：`Controller/ResearchWorkflowDemoController.php`。

默认 API 前缀：`/api`。下文假设服务监听 `http://127.0.0.1:9501`。

## 目录结构

```
Research/
├── Agent/
│   ├── CodingResearchAgent.php      # 编码方向研究 Agent
│   └── FinanceResearchAgent.php     # 财务方向研究 Agent
├── Controller/
│   └── ResearchWorkflowDemoController.php
├── Dto/
│   └── ResearchSummaryDto.php       # summary / urgent / source
└── Workflow/
    ├── MultiAgentResearchWorkflow.php   # multi_agent_research
    └── McpResearchWorkflow.php          # mcp_research
```

## API 一览

| 方法 | 路径 | 说明 |
|------|------|------|
| POST | `/api/v1/research/workflow/multi-agent` | 多 Agent 并行研究 |
| POST | `/api/v1/research/workflow/mcp` | MCP 研究 + 紧急度分支 |
| GET  | `/api/v1/research/workflow/status?runId=` | 查询 Run |

公共入参：

| 字段 | 必填 | 说明 |
|------|------|------|
| `query` | 是 | 研究主题 |
| `userId` | 否 | 默认 `demo-user` |
| `sessionId` | 否 | 默认由 query 派生 |

---

## 流程一：多 Agent 并行（multi_agent_research）

```
parallel_research (coding + finance 并行)
        │
        ▼
     summary  →  data.summary{agentCount, topics}
```

| 字段 | 说明 |
|------|------|
| `useMock` | 默认 `true`：不调 LLM，返回确定性 mock；`false` 走真实 Agent（无密钥时 Fake 回退） |

### curl：mock 并行研究（推荐本地演示）

```bash
curl -s -X POST 'http://127.0.0.1:9501/api/v1/research/workflow/multi-agent' \
  -H 'Content-Type: application/json' \
  -d '{
    "query": "Analyze swoolefy workflow design",
    "useMock": true
  }' | jq .
```

预期：`status=completed`，`agentOutputs` 含 `coding` / `finance`，`summary.agentCount=2`。

### curl：真实 / Fake Agent

```bash
curl -s -X POST 'http://127.0.0.1:9501/api/v1/research/workflow/multi-agent' \
  -H 'Content-Type: application/json' \
  -d '{
    "query": "Compare coroutine vs thread for IO-bound services",
    "useMock": false
  }' | jq .
```

未设置 `OPENAI_API_KEY` 时，各 Agent 使用内置 `FakeAIProvider` 返回固定文案。

---

## 流程二：MCP 研究（mcp_research）

```
research (声明 MCP: github, brave_search；默认 stub)
    │
    ▼
summarize (ResearchSummaryDto → state.summary)
    │
    ├── urgent == true  → notify
    └── urgent == false → archive
```

默认紧急判定：`query`（忽略大小写）包含 `urgent`。

| 字段 | 说明 |
|------|------|
| `mockSummary` | 可选，覆盖摘要：`urgent` / `summary` / `source` |
| `useRealResearchAgent` | 默认 `false`：research 用 stub；`true` 走 `CodingResearchAgent` |

### curl：非紧急 → archive

```bash
curl -s -X POST 'http://127.0.0.1:9501/api/v1/research/workflow/mcp' \
  -H 'Content-Type: application/json' \
  -d '{
    "query": "Weekly architecture notes"
  }' | jq .
```

预期：`summary.urgent=false`，`archived=true`，`notified` 为空。

### curl：紧急（query 含 urgent）→ notify

```bash
curl -s -X POST 'http://127.0.0.1:9501/api/v1/research/workflow/mcp' \
  -H 'Content-Type: application/json' \
  -d '{
    "query": "urgent security patch review"
  }' | jq .
```

预期：`summary.urgent=true`，`notified=true`。

### curl：mockSummary 强制紧急分支

```bash
curl -s -X POST 'http://127.0.0.1:9501/api/v1/research/workflow/mcp' \
  -H 'Content-Type: application/json' \
  -d '{
    "query": "normal topic",
    "mockSummary": {
      "urgent": true,
      "summary": "Forced urgent from demo",
      "source": "demo"
    }
  }' | jq .
```

### curl：真实 research Agent（可选）

```bash
curl -s -X POST 'http://127.0.0.1:9501/api/v1/research/workflow/mcp' \
  -H 'Content-Type: application/json' \
  -d '{
    "query": "urgent dependency CVE triage",
    "useRealResearchAgent": true
  }' | jq .
```

---

## 查询状态

```bash
curl -s 'http://127.0.0.1:9501/api/v1/research/workflow/status?runId=<runId>' | jq .
```

## 响应字段说明

| 字段 | 说明 |
|------|------|
| `runId` | 运行实例 ID |
| `workflowId` | `multi_agent_research` / `mcp_research` |
| `status` | `completed` / `failed` / … |
| `query` | 研究主题 |
| `agentOutputs` | 多 Agent 并行结果（coding / finance） |
| `summary` | 汇总或结构化摘要 |
| `notified` / `archived` | MCP 分支结果 |
| `content` / `mcpToolsUsed` | research 节点产出 |
| `data` | 完整 state.data |

---

## 代码用法（非 HTTP）

```php
use Swoolefy\Support\Workflow\WorkflowComponentFactory;
use Test\Module\Research\ResearchWorkflowService;
use Test\Module\Research\Workflow\McpResearchWorkflow;
use Test\Module\Research\Workflow\MultiAgentResearchWorkflow;

// 多 Agent（本模块 Registry / Engine）
$def = MultiAgentResearchWorkflow::definition(
    ResearchWorkflowService::agentScheduler(),
    useMockAgents: true,
);
$runId = ResearchWorkflowService::engine()->start(
    WorkflowComponentFactory::compiler()->compile($def),
    ['query' => 'Analyze workflow design'],
);

// MCP
$def = McpResearchWorkflow::definition(ResearchWorkflowService::neuronFactory());
$runId = ResearchWorkflowService::engine()->start(
    WorkflowComponentFactory::compiler()->compile($def),
    ['query' => 'urgent security review'],
);
```

---

## 相关文档

- 订单工作流演示：`Test/Module/Order/README.md`
- 通用工作流控制器：`Test/Module/Workflow/Controller/WorkflowController.php`
- 单测：`src/Support/Workflow/Tests/WorkflowPhase2Test.php`（multi agent）
)
