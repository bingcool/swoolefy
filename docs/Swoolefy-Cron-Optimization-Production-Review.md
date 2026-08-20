# Swoolefy-Cron-Optimization-Production-Review

> 基于 `swoolefy-6.2-x` 当前分支代码、已落地的 `Swoolefy-Cron-Production-Architecture-Review.md` 与 Web Admin 实现进行复核。
>
> 目标：**不重新设计 Cron，不新增独立基础设施，只修复当前实现中的正确性、性能、数据一致性和可维护性问题。**

## P0 落地状态

| 项 | 状态 |
|---|---|
| [x] P0-1 RunOnce request 精确消费 | 已修复 |
| [x] P0-2 Invalid Row 不误 DELETE | 已修复 |
| [x] P0-3 Timer arm 最终异常保护 | 已修复 |

## 1. 本次复核范围

重点检查：

```text
Test/Module/Cron
    ├── Controller/CronTaskManagerController.php
    ├── Service/CronTaskManagerService.php
    ├── Service/CronTaskService.php
    ├── Service/CronTaskPayloadBuilder.php
    ├── CronTaskEventTrait.php
    └── DTO / Request / Response

src/Worker/Cron
    ├── CronProcess.php
    ├── CronManager.php
    ├── ConfigDiff.php
    ├── RuntimeJobRegistry.php
    ├── CronScheduler.php
    ├── ExpressionParser.php
    ├── SwooleCronTimer.php
    ├── TaskDefinition.php
    ├── ExecutionGuard.php
    ├── ExecutionSnapshot.php
    ├── ShellExecutor / HttpExecutor
    └── Metrics / Diagnostics
```

当前仓库已经具备完整的 DB → Polling → ConfigDiff → Runtime Registry → Scheduler → One-shot Timer → Executor → Log 链路，并且已经实现 Dashboard、Expression Preview、Execution Detail、Runtime Overview、Run Once、Node 管理等 Web Admin 能力。citeturn5view0turn5view1turn6view2

---

# 2. 总体结论

这一版 Cron 已经从“简单 DB 定时拉取”升级成了一个结构比较清晰的生产级 Cron Engine。

核心设计目前是正确的：

```text
cron_task
   ↓
DB Polling
   ↓
ConfigDiff
   ↓
RuntimeJobRegistry
   ↓
CronScheduler
   ↓
One-shot Timer
   ↓
ExecutionGuard
   ↓
ExecutionSnapshot
   ↓
Shell / HTTP Executor
   ↓
cron_task_log
```

而且已经明确保持：

```text
Database       = 配置
Runtime        = 运行态
Scheduler      = 什么时候执行
Executor       = 怎么执行
Log            = 发生了什么
```

这与之前的生产架构方案是一致的。fileciteturn5file8L1038-L1093

**现在不建议继续加 Scheduler、Redis、MQ、Leader Election、分布式锁等复杂能力。**

下一阶段最有价值的工作应该是：

> **把已经实现的能力做“正确”，然后把高频路径上的数据库查询和日志统计优化掉。**

---

# 3. 优先级总览

| 优先级 | 问题 | 类型 | 建议 |
|---|---|---|---|
| P0 | `runOnce` 多请求被一次执行吞掉 | 正确性 / 数据丢失 | 已修复 |
| P0 | 单条非法配置会被 Diff 解释成 DELETE | 正确性 | 已修复 |
| P0 | `onTrigger()` 下一 Timer arm 缺少最终兜底 | Worker 稳定性 | 已修复 |
| P1 | `hasPendingRunOnce()` 每个任务一次 DB 查询 | 性能 | 必须优化 |
| P1 | `taskLogs()` status 过滤发生在分页之后 | 分页正确性 | 必须修 |
| P1 | Dashboard/Trend 全量加载日志到 PHP 内存 | 性能 / OOM | 必须修 |
| P1 | `taskStats()` 依赖 message 文本解析 | 数据可靠性 | 建议优化 |
| P1 | `updated_at` 进入 fingerprint 导致无意义 Timer 重建 | 性能 | 建议修 |
| P1 | HTTP timeout 被强制抬到 120 秒 | 配置语义 | 建议修 |
| P1 | DB Polling 每轮全量 SELECT `*` | 性能 | 建议优化 |
| P1 | Node 删除缺少“仍有任务绑定”保护 | 数据一致性 | 建议修 |
| P2 | `CronTaskEventTrait` 仍存在 todo + var_dump | 代码质量 | 清理 |
| P2 | Cron 管理 Service 职责继续膨胀 | 可维护性 | 暂不拆大模块 |
| P2 | 日志表软删除 / retention 策略 | 运维 | 后续 |

---

# 4. P0-1：Run Once 队列存在请求丢失

> **状态：已修复。** 一条 `cron_task_run_request` 对应一次 Execution；`ackRunOnce($requestId)` 按主键消费；Polling 按 `run_once_request_ids` 逐条执行。

## 当前实现

管理端执行一次任务时，会插入：

```text
cron_task_run_request

cron_id
requested_at
consumed_at
```

`enqueueRunOnce()` 每次请求都会插入一条记录。citeturn10view1

但是 Worker 侧：

```php
hasPendingRunOnce($cronTaskId)
```

只判断是否存在待消费记录。

随后：

```php
runOnceNow($jobId);
```

只执行一次。

最后：

```php
ackRunOnce($cronTaskId);
```

会：

```php
where('cron_id', $cronTaskId)
whereNull('consumed_at')
update(['consumed_at' => ...])
```

也就是说：

```text
点击执行一次
点击执行一次
点击执行一次
        ↓
3 条 pending
        ↓
Worker Polling
        ↓
runOnceNow()
        ↓
只执行 1 次
        ↓
ackRunOnce()
        ↓
3 条全部 consumed
```

**后两次执行请求实际上丢失。**

当前 `CronManager::consumeRunOnceRequests()` 也明确通过 `$seen[$jobId]` 保证同一个 job 每轮只执行一次。citeturn6view0

## 级别

**P0 正确性问题。**

因为这是一个已经暴露给 Web Admin 的“立即执行”功能，用户看到的是：

```text
请求 A
请求 B
请求 C
```

但实际：

```text
只执行 A
```

---

## 修复原则

不新增 MQ。

继续使用当前：

```text
cron_task_run_request
```

但必须实现：

> **一条 RunOnce Request 对应一次 Execution。**

推荐 Worker 每次只消费一条。

伪代码：

```php
$request = findOnePendingRequest($cronId);

if ($request === null) {
    return;
}

$result = $this->runOnceNow($jobId);

if ($result->isCompleted()) {
    ackRequest($request->id);
}
```

而不是：

```php
ackAllPendingRequests($cronId);
```

---

## 推荐修改位置

```text
Test/Module/Cron/Service/CronTaskManagerService.php

hasPendingRunOnce()
ackRunOnce()
```

以及：

```text
src/Worker/Cron/CronManager.php

consumeRunOnceRequests()
```

建议把：

```php
ackRunOnce(int $cronTaskId)
```

改为：

```php
ackRunOnce(int $requestId)
```

这样一条队列记录对应一条消费。

---

# 5. P0-2：非法任务配置被错误解释成 DELETE

> **状态：已修复。** `applyRows()` 记录 `$invalidJobIds`，ConfigDiff 对 protected invalid 禁止 DELETE，保留 Last Known Good Runtime。

这是当前实现里一个非常值得修复的问题。

`CronManager::applyRows()`：

```php
try {
    $definition = TaskDefinition::fromArray($row);
} catch (\Throwable $e) {
    $this->debug(...);
    continue;
}
```

看起来是：

> 单条非法配置跳过，不影响其它任务。

但是实际上：

```text
Runtime:
A
B
C

DB:
A
B(非法)
C
```

进入：

```text
desired:
A
C
```

然后：

```text
ConfigDiff
Runtime B 存在
Desired B 不存在
        ↓
DELETE B
```

所以：

> **“跳过非法任务”最终会变成“删除 Runtime 中原本正常运行的任务”。**

这与当前 CronManager 的注释意图并不完全一致。当前架构明确要求 DB 故障时保留 Last Known Good Runtime。citeturn9view0turn11view0

## 风险

后台误改一条任务：

```text
expression = invalid
```

Worker Polling：

```text
TaskDefinition parse failed
        ↓
desired 不包含该任务
        ↓
ConfigDiff DELETE
        ↓
旧 Timer 被清除
```

任务直接停止。

---

## 修复方案

将配置同步区分成：

```text
VALID
INVALID
```

对于：

```text
已有 Runtime Job
+
本轮 DB Row 存在
+
Row 无法解析
```

应该：

```text
保留 Last Known Good Runtime
```

而不是 DELETE。

只有：

```text
DB 查询成功
+
任务明确不存在
```

才允许 DELETE。

推荐：

```php
$invalidJobIds = [];
```

在 `applyRows()` 中记录非法 Job。

Diff 时：

```text
desired
+
protectedInvalidIds
```

对于 protected invalid：

```text
禁止 DELETE
```

---

# 6. P0-3：Timer arm 最终异常保护

> **状态：已修复。** `onTrigger()` 将 `scheduler->arm()` 包在 try/catch 中，失败记 `recordSchedulerError` 并返回；下一轮 Config Polling `reconcileMissingTimers()` 补回 Timer。

当前 `onTrigger()`：

```text
timer callback
   ↓
job->timerId = 0
   ↓
scheduler->arm()
   ↓
runExecutionPipeline()
```

并且架构明确要求：

> Trigger 后先 arm 下一次 Timer。citeturn6view0

这个顺序是正确的。

但是：

```php
$this->scheduler->arm($job, $planned);
```

如果发生不可预期异常：

```text
Timer API exception
Timer allocation failure
Schedule exception
```

后续：

```text
Execution
```

就不会继续执行。

更重要的是：

```text
job->timerId = 0
```

已经发生。

最终可能形成：

```text
Active Job
+
Timer = 0
```

---

## 修复

只需要增加最终异常隔离，不改变 Scheduler 设计：

```php
try {
    if ($job->isSchedulable()) {
        $this->scheduler->arm($job, $planned);
    }
} catch (\Throwable $e) {
    $this->debug(...);
    $this->metrics->recordSchedulerError(...);
    return;
}
```

然后由下一轮 Config Polling 重新 reconcile。

**不新增 Scheduler。**

---

# 7. P1：RunOnce 每个任务一次 DB 查询

当前：

```php
fetchShellCronTask()
```

每个任务：

```php
$this->hasPendingRunOnce((int) $item['id']);
```

HTTP 同样如此。citeturn4view0

假设：

```text
1000 tasks
Polling = 5s
```

那么每轮：

```text
1 SELECT tasks
+
1000 SELECT run_once
```

每 5 秒一次。

这是明显的 N+1。

---

## 优化

Polling 时一次性读取：

```sql
SELECT cron_id
FROM cron_task_run_request
WHERE consumed_at IS NULL
  AND cron_id IN (...)
```

得到：

```php
$pendingRunOnceIds = [
    1 => true,
    18 => true,
    29 => true,
];
```

然后：

```php
$arr['run_once_requested'] =
    isset($pendingRunOnceIds[$item['id']]);
```

从：

```text
N + 1
```

变成：

```text
2 queries
```

---

# 8. P1：taskLogs() 的 status 分页存在逻辑错误

当前：

```php
$total = $qb->clone()->count();

$list = $qb
    ->order(...)
    ->limit(...)
    ->select();
```

然后才：

```php
if ($statusFilter !== null) {
    if (classifyMessage(...) !== $statusFilter) {
        continue;
    }
}
```

也就是说：

```text
数据库分页
    ↓
取 20 条
    ↓
PHP 过滤 status
```

例如真实数据：

```text
20 条
其中只有 2 条 success
```

页面只返回：

```text
2 条
```

但是下一页又继续从原始数据第 21 条开始。

最终：

```text
分页结果不完整
total 不准确
```

当前实现确实是先分页再按 message 分类。citeturn6view1

---

## 最优修复

因为 status 当前来自：

```text
message
```

建议第一阶段不改变表结构的情况下：

```text
如果 status filter != null
    不做普通 offset pagination
    使用足够大的候选集继续向后读取
```

但这只是过渡方案。

生产级正确方案应该是：

```text
cron_task_log
增加明确 execution status
```

例如：

```text
status:
1 running
2 success
3 failed
4 skipped
```

然后：

```sql
WHERE cron_id = ?
AND status = ?
ORDER BY id DESC
LIMIT ?, ?
```

不过这属于**数据模型增强**。

如果当前阶段坚持“不改表”，则把 status 过滤问题标记为 P1，先修 API 语义。

---

# 9. P1：Dashboard / Trend 可能产生大规模内存读取

当前 Dashboard：

```php
$logs = CronTaskLogEntity::query()
    ->field(['message'])
    ->where('created_at', '>=', $todayStart)
    ->select()
    ->toArray();
```

然后 PHP 循环统计。citeturn6view2

Trend 同样：

```php
SELECT message, created_at
FROM cron_task_log
WHERE created_at >= ?
```

然后全部加载到 PHP。citeturn6view2

如果一天：

```text
10 万日志
100 万日志
1000 万日志
```

Dashboard 请求就可能变成：

```text
MySQL
 ↓
大量 rows
 ↓
PHP Array
 ↓
PHP 分类
 ↓
HTTP Response
```

这不是 Cron Engine 的调度 bug，但属于明显的生产性能问题。

---

## 修复方向

第一阶段不增加新表。

直接数据库聚合：

```sql
COUNT(*)
SUM(...)
GROUP BY
```

Trend：

```sql
GROUP BY DATE_FORMAT(created_at, ...)
```

Stats：

```sql
COUNT
AVG
```

最终：

```text
DB 聚合
   ↓
少量统计结果
   ↓
PHP DTO
```

而不是：

```text
DB 明细
   ↓
PHP 全量计算
```

---

# 10. P1：taskStats() 不应该依赖 message 文本判断状态

当前统计：

```php
strpos($message, '成功')
strpos($message, '失败')
strpos($message, '跳过')
```

并且只取最近：

```text
2000 logs
```

当前代码也明确说明这是“非严谨状态机统计，仅供运营看板参考”。citeturn6view1

这意味着：

```text
日志文案
=
数据协议
```

非常脆弱。

例如：

```text
执行失败，但 message 同时包含“成功”
```

可能产生错误分类。

---

## 第一阶段建议

如果暂时不改表：

统一 Cron 内部结果消息格式：

```text
[status=success] ...
[status=failed] ...
[status=skipped] ...
```

统一由：

```text
ExecutionResult
```

负责生成。

后续如果允许 schema 优化，再把：

```text
status
duration_ms
started_at
finished_at
```

结构化进入 `cron_task_log`。

---

# 11. P1：updated_at 被纳入 fingerprint

`ConfigDiff` 明确把：

```text
updatedAt
```

放进 fingerprint。citeturn11view0

因此：

```text
修改 description
```

也可能产生：

```text
UPDATE
 ↓
clear Timer
 ↓
重新 parse
 ↓
重新 arm Timer
```

但是：

```text
description
```

根本不影响调度。

---

## 推荐

fingerprint 只应该包含：

```text
expression
execType
command
status
withBlockLapping
cronBetween
cronSkip
HTTP 配置
retry
timezone
```

而：

```text
name
description
updatedAt
```

不应该导致 Scheduler 重建。

当前 `cronName` 已经被排除，这是正确方向；`updatedAt` 也应该排除。citeturn11view3

---

# 12. P1：HTTP Timeout 语义需要统一

当前 Builder：

```php
httpRequestTimeOut
```

创建时默认：

```text
30
```

但 Worker `CronTaskService`：

```php
if timeout < 120:
    timeout = 120
```

因此：

```text
Web Admin:
30 seconds

实际 Worker:
120 seconds
```

这会让后台配置页面产生误导。

当前代码确实存在这个 30 → 120 的转换。citeturn12view0turn4view0

---

## 推荐

不要在 Worker 静默修改用户配置。

统一：

```text
0 = 使用默认值
>0 = 使用用户配置
```

如果必须设置最小值：

```text
Builder 校验
```

直接拒绝：

```text
timeout < MIN
```

而不是：

```text
保存 30
实际执行 120
```

---

# 13. P1：DB Polling 不应该长期 `SELECT *`

当前：

```php
CronTaskEntity::queryNotDeleted()
    ->field('*')
```

会把整个任务字段全部拉入 Worker。citeturn2view0

随着：

```text
http_body
http_headers
task metadata
```

变大，Polling 成本会越来越高。

但 Cron Polling 真正需要的是：

```text
id
node_id
name
expression
command
exec_type
status
with_block_lapping
cron_between
cron_skip
http_method
http_body
http_headers
http_request_time_out
updated_at
```

以及执行所需的兼容字段。

---

## 推荐

第一阶段不要做复杂增量同步。

只做：

```text
明确 field()
```

避免：

```sql
SELECT *
```

---

# 14. P1：Node 删除应该阻止仍被任务使用的节点

当前：

```php
deleteNode()
```

基本流程是：

```text
load node
↓
delete node
```

而没有看到删除前强制检查：

```text
cron_task.node_id = node.id
```

当前节点列表甚至会展示：

```text
task_count
```

说明系统已经具备这个判断基础。citeturn6view1

生产环境如果：

```text
node 1
 ├── task A
 ├── task B
 └── task C

删除 node 1
```

会留下：

```text
task A/B/C
node_id = 1
```

Worker 无法正常执行。

---

## 修复

删除前：

```php
$count = CronTaskEntity::queryNotDeleted()
    ->where('node_id', $id)
    ->count();

if ($count > 0) {
    throw CronTaskException::throw(
        '节点仍绑定计划任务，请先迁移任务',
        ...
    );
}
```

这是数据一致性保护，不是新功能。

---

# 15. P2：CronTaskEventTrait 中残留调试代码

当前：

```php
protected function onBeforeSave(): bool
{
    // todo
    var_dump(...);
    return true;
}
```

以及：

```text
onBeforeInsert
onAfterInsert
onBeforeUpdate
onAfterUpdate
```

仍然存在 `todo + var_dump`。citeturn4view3

这不应该进入生产路径。

建议：

```text
删除无实际用途的 Trait
```

或者：

```text
如果没有业务逻辑
→ 删除 Trait 引用
```

不要保留空生命周期钩子。

---

# 16. P2：CronTaskManagerService 暂时不要继续拆分

当前 Service 已经比较大，但目前它仍然围绕：

```text
Task CRUD
Node
Execution Log
Stats
Dashboard
Runtime
Agent
RunOnce
```

展开。

现在不建议立即拆成：

```text
TaskService
NodeService
DashboardService
RuntimeService
ExecutionService
AgentService
```

原因：

当前它还是一个 Test Application 中的 Cron 管理模块，不是独立 SaaS 产品。

继续拆分反而会增加：

```text
DTO
Dependency
Service
Controller
```

数量。

**先解决真正的性能和正确性问题。**

---

# 17. 建议保留的当前架构

以下设计不要改：

## 17.1 DB Polling

继续：

```text
cron_task
    ↓
Polling
```

这是未来 Web Admin 管理的核心基础。

架构文档已经明确要求：

> Web Admin 只修改数据库，Cron Worker 通过 Polling 自动感知变化。fileciteturn5file3L237-L263

---

## 17.2 ConfigDiff

继续保持：

```text
ADD
UPDATE
DELETE
ENABLE
DISABLE
NOOP
```

不要改成：

```text
clear all
reload all
```

当前 ConfigDiff 已经正确实现最小化 Diff 的方向。citeturn11view0

---

## 17.3 One-shot Timer

继续：

```text
nextRunAt
 ↓
after()
 ↓
onTrigger()
 ↓
arm next
```

不要改成一个：

```text
每秒扫描所有任务
```

因为当前 one-shot 模型更符合已经设计好的：

```text
每 Job 一个 Schedule Timer
```

生产不变量也是：

```text
Active Job = Exactly One Schedule Timer
Disabled Job = Zero Schedule Timer
Deleted Job = Zero Schedule Timer
```

这些是不应该破坏的核心不变量。fileciteturn5file4L605-L649

---

# 18. 推荐的下一版优化架构

优化后保持：

```text
                    Web Admin
                        │
                        ▼
                   cron_task
                        │
                  MySQL Polling
                        │
             ┌──────────┴──────────┐
             │                     │
          Valid                  Invalid
             │                     │
             ▼                     ▼
        ConfigDiff          Last Known Good
             │
      ┌──────┼──────┐
      ▼      ▼      ▼
     ADD   UPDATE  DELETE
      │      │      │
      └──────┼──────┘
             ▼
      RuntimeJobRegistry
             │
             ▼
         Scheduler
             │
        nextRunAt
             │
             ▼
       One-shot Timer
             │
             ▼
       Execution Guard
             │
             ▼
      Execution Snapshot
             │
       ┌─────┴─────┐
       ▼           ▼
     Shell        HTTP
       │           │
       └─────┬─────┘
             ▼
        ExecutionResult
             │
       ┌─────┴─────┐
       ▼           ▼
      Log        Metrics
```

关键优化：

```text
DB Error
   ↓
Last Known Good

Invalid Row
   ↓
保留旧 Job

RunOnce
   ↓
一条 request = 一次 execution

Dashboard
   ↓
SQL Aggregate

Polling
   ↓
批量 Pending RunOnce

Fingerprint
   ↓
只包含调度/执行字段
```

---

# 19. 推荐实施顺序

## Phase 1：先修 P0

只做：

```text
1. RunOnce request 精确消费  [x]
2. Invalid Row 不误 DELETE  [x]
3. Timer arm 最终异常保护  [x]
```

完成后进行完整回归。

---

## Phase 2：Polling 性能

```text
1. Pending RunOnce N+1 → 批量查询
2. SELECT * → 明确字段
3. Config Sync 查询优化
```

---

## Phase 3：Web Admin 数据查询

```text
1. taskLogs status 分页修复
2. Dashboard SQL 聚合
3. Execution Trend SQL 聚合
4. taskStats 查询优化
```

---

## Phase 4：调度稳定性

```text
1. fingerprint 去掉 updated_at
2. HTTP timeout 语义统一
3. Node 删除保护
```

---

## Phase 5：清理

```text
1. 删除 CronTaskEventTrait 的 var_dump / todo
2. 清理兼容代码
3. 补充回归测试
```

---

# 20. 必须增加的回归测试

## 20.1 RunOnce

```text
enqueue A
enqueue B
enqueue C

Polling

必须：

Execution = 3
Consumed = 3
```

不能：

```text
Execution = 1
Consumed = 3
```

---

## 20.2 Invalid Configuration

```text
Runtime:
A
B
C

DB:
A
B invalid
C
```

必须：

```text
A → 正常
B → 保留旧 Runtime
C → 正常
```

---

## 20.3 UPDATE

反复：

```text
15
20
25
30
10
```

最终：

```text
timerCountFor(job) == 1
```

不能：

```text
1 → 2 → 3 → 4
```

---

## 20.4 DB Down

```text
正常
 ↓
DB DOWN
 ↓
Polling
 ↓
原任务继续
```

DB 恢复：

```text
DB UP
 ↓
Polling
 ↓
ConfigDiff
 ↓
Runtime 最终一致
```

---

## 20.5 DELETE

```text
Running Execution
        +
DELETE
```

预期：

```text
当前 Execution → 继续
未来 Timer → 0
Execution 完成
Runtime Job → remove
```

当前 CronManager 已经采用“DELETE 不杀正在执行任务”的设计，这个行为应继续保持。citeturn10view3

---

## 20.6 Disable

```text
Enable
 ↓
Timer = 1

Disable
 ↓
Timer = 0

Enable
 ↓
Timer = 1
```

---

## 20.7 with_block_lapping

```text
expression = 5s
duration = 10s
with_block_lapping = 1
```

必须：

```text
00 RUN
05 SKIP
10 RUN
15 SKIP
20 RUN
```

---

## 20.8 Allow overlap

```text
expression = 5s
duration = 10s
with_block_lapping = 0
```

必须：

```text
00 A
05 B
10 C
```

---

# 21. 最终优先级

如果现在让我只选 **3 个最值得立即修改的地方**：

### 第一：RunOnce 消费语义

```text
P0
```

这是实际的数据丢失问题。

### 第二：Invalid Config 不得误 DELETE

```text
P0
```

这是生产环境非常危险的配置容错问题。

### 第三：日志 / Dashboard 查询

```text
P1
```

当前功能越来越完整后，`cron_task_log` 会成为真正的性能瓶颈。

---

# 22. 最终判断

目前 Swoolefy Cron 的**架构方向已经基本正确，不建议推倒重来**。

尤其应该继续坚持：

```text
DB
 ↓
Polling
 ↓
Diff
 ↓
Runtime
 ↓
Scheduler
 ↓
One-shot Timer
 ↓
Executor
```

而不是继续引入：

```text
Redis
MQ
Leader Election
Distributed Lock
独立 Scheduler
```

之前的生产方案本身也明确排除了这些复杂设计。fileciteturn5file2L171-L203

现在真正需要做的是：

> **从“架构已经完整”进入“生产正确性和高负载数据路径优化”阶段。**

其中我认为当前最重要的两个 P0：

```text
P0-1 RunOnce 请求丢失
P0-2 Invalid Row → DELETE
```

这两个修完以后，Cron Engine 的核心闭环会明显更加可靠。

---

## 附：本次代码复核说明

本次尝试直接通过 Git clone 获取仓库时，运行环境无法解析 `github.com`，因此没有伪称“本地 clone 成功”；随后改用该分支当前 GitHub 源码页面和 Raw 文件逐个复核。当前 GitHub 分支页面显示 `swoolefy-6.2-x`，并且 `Test/Module/Cron`、`src/Worker/Cron` 以及 Web Admin Controller/Service 等代码均可直接核验。citeturn0view0turn1view0turn3view0
