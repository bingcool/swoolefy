# Swoolefy Cron Web Admin API 生产级架构设计

> 基于 Swoolefy 6.2-x 当前 `Test/Module/Cron/Controller/CronTaskManagerController.php` 与已确定的 DB-driven Cron 架构。

## 1. 现有 API 基线

当前 Controller 已提供：

| Method | Endpoint | 说明 |
|---|---|---|
| GET | `/api/v1/tasks` | 分页查询任务 |
| POST | `/api/v1/tasks` | 创建任务 |
| PUT | `/api/v1/tasks` | 部分更新任务 |
| DELETE | `/api/v1/tasks` | 删除任务 |
| POST/PUT | `/api/v1/tasks/status` | 启停任务 |
| GET | `/api/v1/nodes` | 节点列表 |
| POST | `/api/v1/nodes` | 创建节点 |
| DELETE | `/api/v1/nodes` | 删除节点 |
| GET | `/api/v1/tasks/logs` | 执行日志 |
| GET | `/api/v1/tasks/stats` | 任务统计 |
| GET | `/api/v1/agent/tasks` | Agent 拉取任务 |
| POST | `/api/v1/agent/heartbeat` | Agent 心跳 |
| POST | `/api/v1/agent/report` | Agent 上报结果 |

当前 Controller 是薄控制器：Request → DTO → `CronTaskManagerService` → Response；路由前缀为 `/api/v1`。这一结构应继续保持。citeturn2view0turn3view0

## 2. 总体边界

```text
Browser
  ↓
Web Admin API
  ↓
CronTaskManagerService
  ↓
MySQL / cron_task
  ↓
Cron Worker Polling
  ↓
Config Diff
  ↓
Runtime Job Registry
  ↓
Scheduler
```

Web Admin **不直接操作 Timer / Scheduler**。数据库仍然是配置中心；Worker 才是运行时调度器。fileciteturn3file0L43-L95

Agent API 独立：

```text
/api/v1/agent/*
```

不能把 Agent API 当作浏览器管理 API。

---

# 3. API 分层

```text
/api/v1
├── /tasks
│   ├── GET /tasks
│   ├── POST /tasks
│   ├── PUT /tasks
│   ├── DELETE /tasks
│   ├── PUT /tasks/status
│   ├── GET /tasks/{id}
│   ├── GET /tasks/logs
│   ├── GET /tasks/stats
│   ├── GET /tasks/{id}/executions/{execBatchId}
│   ├── POST /tasks/expression/preview
│   ├── PUT /tasks/batch-status
│   └── POST /tasks/{id}/duplicate
├── /nodes
│   ├── GET /nodes
│   ├── POST /nodes
│   ├── PUT /nodes/{id}
│   ├── DELETE /nodes
│   └── GET /nodes/{id}
├── /dashboard
│   ├── GET /dashboard/overview
│   └── GET /dashboard/execution-trend
└── /runtime
    └── GET /runtime/overview
```

Agent：

```text
GET  /api/v1/agent/tasks
POST /api/v1/agent/heartbeat
POST /api/v1/agent/report
```

---

# 4. Task API

## 4.1 List

```http
GET /api/v1/tasks?page=1&pageSize=20&keyword=&status=1&nodeId=1&execType=1
```

当前已经支持 `page/pageSize/keyword/status/nodeId/execType`。citeturn2view0

返回建议：

```json
{
  "items": [],
  "page": 1,
  "pageSize": 20,
  "total": 100
}
```

列表字段：

```text
id,name,nodeId,expression,command,execType,status,
withBlockLapping,description,updatedAt,
nextRunAt,nextRunAtAt

`nextRunAt` 为 unix 秒（禁用 / 非法表达式为 `null`）；`nextRunAtAt` 为 `Y-m-d H:i:s`（无则为空串）。HTTP Admin 用 `ExpressionParser` + `calculateNextRunAt` + `TimeWindowFilter` 推算，不读 Cron Worker 内存。
```

## 4.2 Detail【P1】

```http
GET /api/v1/tasks/{id}
```

返回完整 Task Definition：

```json
{
  "id": 1,
  "nodeId": 1,
  "name": "修复用户数据",
  "expression": "15",
  "expressionType": "interval",
  "command": "php script.php start Test",
  "execType": 1,
  "status": 1,
  "withBlockLapping": 0,
  "description": "",
  "cronBetween": null,
  "cronSkip": null,
  "httpMethod": "",
  "httpBody": null,
  "httpHeaders": null,
  "httpRequestTimeOut": 0,
  "createdAt": "...",
  "updatedAt": "..."
}
```

`expressionType` 是展示层派生字段，不修改现有数据库结构。

## 4.3 Create

已有：

```http
POST /api/v1/tasks
```

当前支持 Shell / HTTP，并通过 `TaskPayloadInputDto` 转换。citeturn2view0

秒级 Interval：

```json
{"name":"clean","nodeId":1,"expression":"15","command":"/bin/bash /opt/clean.sh","execType":1,"status":1,"withBlockLapping":1}
```

Linux Cron：

```json
{"name":"sync","nodeId":1,"expression":"*/5 * * * *","command":"https://example.com/api/sync","execType":2,"httpMethod":"POST","httpRequestTimeOut":30,"status":1}
```

## 4.4 Update

已有：

```http
PUT /api/v1/tasks
```

必须保持部分更新语义。修改 `expression/status/nodeId/command` 后，Worker 通过 Polling → Diff → Timer 生命周期处理，不由 Web API 直接触碰 Scheduler。

## 4.5 Delete

已有：

```http
DELETE /api/v1/tasks
```

语义：DB soft delete → Worker 发现删除 → Runtime 移除 → 清理 Timer。

## 4.6 Status

已有：

```http
PUT /api/v1/tasks/status
```

```json
{"id":1,"status":1}
```

必须是幂等赋值，不采用 `toggle=true`。

---

# 5. Expression Preview【P1】

Web Admin 创建任务时强烈需要实时验证。

```http
POST /api/v1/tasks/expression/preview
```

请求：

```json
{"expression":"15"}
```

返回：

```json
{
  "valid": true,
  "type": "interval",
  "description": "每 15 秒执行一次",
  "nextRuns": ["...","...","..."]
}
```

Linux Cron：

```json
{"expression":"*/5 * * * *"}
```

必须统一经过现有 Scheduler/Expression Parser 的校验逻辑，避免 Web API 自己实现第二套 Cron 解析器。

---

# 6. Execution API

## 6.1 Logs

已有：

```http
GET /api/v1/tasks/logs?taskId=1&page=1&pageSize=20
```

当前已经支持任务日志分页。citeturn3view0

建议增加可选筛选：

```text
execBatchId
status
startTime
endTime
```

## 6.2 Execution Detail【P1】

```http
GET /api/v1/tasks/{id}/executions/{execBatchId}
```

返回：

```json
{
  "taskId": 1,
  "execBatchId": "batch-xxx",
  "status": "success",
  "pid": 12345,
  "startedAt": "...",
  "finishedAt": "...",
  "durationMs": 120,
  "taskItem": {},
  "message": "..."
}
```

`cron_id + exec_batch_id` 是一次 Execution 的核心追踪键。

## 6.3 Stats

已有：

```http
GET /api/v1/tasks/stats?taskId=1
```

当前已有 `total/success/failed/skipped/successRate/avgDurationMs/samples`。citeturn3view0

---

# 7. Dashboard API【P1】

## Overview

```http
GET /api/v1/dashboard/overview
```

```json
{
  "tasks":{"total":100,"enabled":80,"disabled":20},
  "executions":{"today":12500,"success":12100,"failed":300,"skipped":100},
  "nodes":{"total":5,"online":5,"offline":0}
}
```

首页用一个聚合接口，避免前端同时请求多个统计 API。

## Trend

```http
GET /api/v1/dashboard/execution-trend?range=24h
```

支持：

```text
24h / 7d / 30d
```

返回每个时间桶：

```json
{"time":"02:00","total":120,"success":115,"failed":5}
```

---

# 8. Node API

现有：

```text
GET  /nodes
POST /nodes
DELETE /nodes
```

citeturn3view0

建议补：

```http
GET /api/v1/nodes/{id}
PUT /api/v1/nodes/{id}
```

Detail 可展示：

```text
id
nodeName
nodeIp
remark
status
lastHeartbeatAt
taskCount
```

其中 `status/lastHeartbeatAt` 只有在现有 Agent 心跳实现有可靠来源时才能返回；不能为了 UI 凭空推断。

---

# 9. Runtime API【P1】

Swoolefy 已经有 Runtime Metrics / Diagnostics，因此 Cron Admin 不应重新实现第二套 Runtime 系统。已有 Runtime 设计明确支持 Worker、Metrics、Pool、Coroutine、Memory、Diagnostics。fileciteturn4file5L519-L585

可提供聚合入口：

```http
GET /api/v1/runtime/overview
```

```json
{
  "scheduler":{"jobs":80,"enabled":75,"running":3},
  "sync":{"lastSuccessAt":"...","lastErrorAt":null},
  "nodes":{"online":5,"offline":0}
}
```

如果现有 Runtime Diagnostics 已提供等价 API，优先复用。

---

# 10. 管理增强

## Batch Status【P1】

```http
PUT /api/v1/tasks/batch-status
```

```json
{"ids":[1,2,3],"status":1}
```

## Duplicate【P1】

```http
POST /api/v1/tasks/{id}/duplicate
```

默认：

```text
status = 0
name = 原名称-copy
```

避免复制任务后立即进入生产调度。

## Manual Run【后续】

```http
POST /api/v1/tasks/{id}/run
```

Manual Run 不修改 `cron_task`，只产生一次 Execution。若 Cron Engine 尚未实现该能力，则只预留 API，不伪装为已实现。

---

# 11. Audit Log【生产建议】

Web Admin 生产环境建议增加独立审计表：

```text
cron_admin_audit_log
```

字段：

```text
id
operator_id
operator_name
action
resource_type
resource_id
before
after
ip
user_agent
created_at
```

记录：

```text
CREATE_TASK
UPDATE_TASK
ENABLE_TASK
DISABLE_TASK
DELETE_TASK
MANUAL_RUN
CREATE_NODE
DELETE_NODE
```

不要把管理审计写进 `cron_task_log`；后者是任务运行日志。

---

# 12. 安全

## Shell

`command` 是高风险字段，应限制管理员权限并审计修改。

## HTTP Secret

`Authorization/Cookie/Token` 等敏感 Header 不应在列表或普通详情 API 明文返回。编辑时采用重新提交模型。

## Auth

如果接入 Swoolefy 已有 Auth，应复用 `FrameworkContext` / 既有认证链，而不是另建 Cron 用户系统。已有 Auth 设计明确推荐 `FrameworkContext::userOrFail()`，并区分用户鉴权与服务间 API Key。fileciteturn3file7L570-L591

权限建议：

```text
cron:task:view
cron:task:create
cron:task:update
cron:task:delete
cron:task:status
cron:task:run
cron:log:view
cron:node:view
cron:node:manage
cron:runtime:view
```

---

# 13. API 幂等性

必须保证：

```text
PUT /tasks/status
```

是赋值，而非 toggle。

DELETE 第二次请求的语义要统一：要么幂等成功，要么统一 `NOT_FOUND`；前后端不得自行猜测。

---

# 14. Web UI 页面

```text
Dashboard
Tasks
 ├── List
 ├── Create
 ├── Edit
 └── Detail
Executions
Nodes
Runtime
```

任务列表：

```text
名称 / Expression / Node / Type / Status / Running / Last Run / Success Rate / Actions
```

编辑页：

```text
基础信息
调度配置
执行配置
执行策略
```

Expression 编辑器必须明确区分：

```text
○ 每 N 秒
○ Linux Cron
```

这样 `15` 与 `*/5 * * * *` 不会在 UI 上产生歧义。

---

# 15. 最终 API 优先级

## P0：稳定现有接口

```text
GET    /tasks
POST   /tasks
PUT    /tasks
DELETE /tasks
PUT    /tasks/status
GET    /nodes
POST   /nodes
DELETE /nodes
GET    /tasks/logs
GET    /tasks/stats
```

## P1：最值得新增

```text
GET  /tasks/{id}
POST /tasks/expression/preview
GET  /tasks/{id}/executions/{execBatchId}
GET  /dashboard/overview
GET  /dashboard/execution-trend
GET  /runtime/overview
GET  /nodes/{id}
PUT  /nodes/{id}
PUT  /tasks/batch-status
POST /tasks/{id}/duplicate
```

## 后续

```text
POST /tasks/{id}/run
GET  /audit-logs
```

---

# 16. 最终调用链

```text
                    Web Browser
                         │
                         ▼
                 ┌──────────────┐
                 │ Web Admin UI │
                 └──────┬───────┘
                        │
                        ▼
                 Cron Admin API
                        │
              ┌─────────┼─────────┐
              ▼         ▼         ▼
            Tasks     Nodes   Dashboard
              │         │         │
              └─────────┼─────────┘
                        ▼
              CronTaskManagerService
                        │
                        ▼
                      MySQL
                        │
                    Polling
                        ▼
                   Config Diff
                        ▼
               Runtime Job Registry
                        ▼
                    Scheduler
                        ▼
                    Executor
                   /         \
                Shell        HTTP
                   \         /
                    ▼       ▼
                  cron_task_log
```

Agent 独立：

```text
Cron Agent
  ├── /agent/tasks
  ├── /agent/heartbeat
  └── /agent/report
```

当前 Controller 已明确包含这三类 Agent API，因此 Web Admin 不应重复实现。citeturn3view0

# 17. 实施顺序

### Phase 1

稳定现有 API Contract。

### Phase 2

新增：

```text
Task Detail
Expression Preview
Execution Detail
Dashboard Overview
Runtime Overview
```

### Phase 3

新增：

```text
Batch Status
Duplicate
Node Detail/Edit
```

### Phase 4

再考虑：

```text
Manual Run
Audit Log
```

## 结论

当前 `CronTaskManagerController` 已经具备完整管理 API 的骨架，包含任务 CRUD、状态、节点、日志、统计和 Agent 通信。citeturn2view0turn3view0

因此 Web Admin 最正确的路线不是推翻，而是：

```text
现有 API
   ↓
稳定 Contract
   ↓
补 Detail / Preview / Dashboard / Execution Detail
   ↓
Web UI
   ↓
继续通过 DB 驱动 Cron Worker
```

核心原则始终保持：

> **Web Admin 管数据库配置，不直接管理 Scheduler Timer。**
