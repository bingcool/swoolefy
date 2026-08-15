# Swoolefy-Cron-Production-Architecture-Review

> Swoolefy 6.2-x Cron 生产级技术架构设计
>
> 核心定位：**数据库驱动 + 常驻 Worker + 动态配置同步 + 秒级 Interval / Linux Cron + Shell / HTTP 执行 + 执行日志**。
>
> 本方案不把 Cron 设计成独立的新基础设施，而是在 Swoolefy 现有 Worker、Coroutine、Runtime 和 `Module/Cron` 基础上完善正确性、调度生命周期和 Web 后台可管理能力。

---

# 1. 总体目标

Swoolefy Cron 最终解决：

```text
1. 数据库配置计划任务
2. Worker 定时拉取数据库配置
3. Runtime 动态增删改任务
4. Scheduler 根据 expression 计算执行时间
5. Shell / HTTP 执行任务
6. cron_task_log 记录执行结果
7. Web Admin 只修改数据库，不直接操作 Worker Timer
```

总体链路：

```text
Web Admin
    ↓
cron_task
    ↓
DB Polling
    ↓
Config Sync / Diff
    ↓
Runtime Job Registry
    ↓
Scheduler
    ↓
Execution Guard
    ↓
Shell / HTTP Executor
    ↓
cron_task_log
```

职责边界：

```text
Database      = 任务配置
Config Sync   = DB → Runtime
Runtime       = 当前运行状态
Scheduler     = 什么时候执行
Executor      = 怎么执行
Log           = 执行记录
```

---

# 2. 现有数据库模型

## 2.1 cron_task

现有表已经能够承担 Cron 配置中心职责，不需要为了架构增加新的任务主表。

核心字段：

| 字段 | 职责 |
|---|---|
| `id` | 任务 ID |
| `node_id` | Cron 节点 |
| `name` | 任务名称 |
| `expression` | 调度表达式 |
| `command` | Shell 命令 / HTTP URL |
| `exec_type` | 1 Shell / 2 HTTP |
| `status` | 0 禁用 / 1 启用 |
| `with_block_lapping` | 是否允许任务重叠 |
| `cron_between` | 允许执行时间段 |
| `cron_skip` | 跳过执行时间段 |
| `http_method` | HTTP Method |
| `http_body` | HTTP Body |
| `http_headers` | HTTP Headers |
| `http_request_time_out` | HTTP 超时（秒） |
| `updated_at` | 配置更新时间 |
| `deleted_at` | 软删除 |

## 2.2 cron_task_log

```text
cron_id
exec_batch_id
pid
task_item
message
created_at
updated_at
deleted_at
```

核心关系：

```text
cron_id
    ↓
哪个任务

exec_batch_id
    ↓
哪一次 Execution
```

日志只负责记录执行过程，不负责保存 Scheduler 当前状态。

---

# 3. expression 双模式

这是当前 Cron 模型的核心规则。

`expression` 同时支持：

```text
秒级 Interval
+
Linux Cron 分钟级表达式
```

## 3.1 秒级 Interval

纯数字表示间隔秒数。

```text
15 → 每 15 秒
20 → 每 20 秒
25 → 每 25 秒
```

例如：

```text
expression = 15
```

计划时间：

```text
10:00:00
10:00:15
10:00:30
10:00:45
10:01:00
...
```

这里的 `15` **不是 Linux Cron 的 minute 字段，而是 15 秒间隔**。

## 3.2 Linux Cron

例如：

```text
*/5 * * * *
```

表示每 5 分钟。

```text
0 * * * *
```

表示每小时整点。

```text
30 2 * * *
```

表示每天 02:30。

统一解析：

```text
expression
├── 纯数字
│   └── Second Interval
└── Cron Expression
    └── Linux Cron
```

最终两种模式都必须输出：

```text
nextRunAt
```

Scheduler 不应该关心表达式字符串细节。

---

# 4. Expression Parser

推荐形成统一职责：

```text
Expression
    ↓
Parser
    ↓
Schedule
    ↓
calculateNextRunAt()
```

## 秒级

```text
15
 ↓
IntervalSchedule(15s)
 ↓
nextRunAt
```

## Linux Cron

```text
*/5 * * * *
 ↓
CronSchedule
 ↓
nextRunAt
```

不要在 Scheduler 中同时写：

```text
字符串判断
Cron 解析
Timer 计算
业务条件
```

否则 Scheduler 很快会变成无法维护的巨型类。

---

# 5. Scheduler 设计

推荐以 **One-shot Timer** 为核心。

```text
Job
 ↓
calculate nextRunAt
 ↓
Timer::after(delay)
 ↓
Trigger
 ↓
Execution
 ↓
calculate nextRunAt
 ↓
Timer::after(delay)
```

不推荐所有任务统一：

```text
Timer::tick(1000)
 ↓
每秒扫描所有 Job
```

原因是任务数量增长后会持续产生全量遍历和时间判断。

---

# 6. 秒级调度避免漂移

对于：

```text
expression = 15
```

不能简单按照：

```text
finish_time + 15s
```

计算下一次执行，否则任务执行耗时会造成时间漂移。

例如计划点：

```text
00 / 15 / 30 / 45
```

即使一次执行耗时 5 秒，也应该继续围绕计划时间计算，而不是变成：

```text
05 / 25 / 45 / 65
```

因此 Scheduler 应以 Schedule 产生的 `nextRunAt` 为准。

---

# 7. Runtime Job

Runtime 中一个 Job 至少需要能够表达：

```text
jobId
definition
timerId
nextRunAt
running
lastRunAt
lastFinishAt
```

其中：

```text
definition = DB 配置在 Runtime 中的快照

timerId = 当前 Schedule Timer

running = 当前是否存在执行实例

nextRunAt = 下一次计划时间
```

不需要为了这些状态额外引入复杂分布式组件。

---

# 8. DB Polling / Config Sync

数据库是 Cron 的配置中心。

Worker 定时：

```text
Polling Timer
    ↓
Query cron_task
    ↓
Validate
    ↓
Diff
    ↓
Apply Runtime
```

查询范围：

```sql
WHERE node_id = :node_id
AND deleted_at IS NULL
```

不要让 DB Sync 负责执行任务。

```text
Config Sync ≠ Executor
```

这样 DB 暂时故障时不会直接把执行链拖垮。

---

# 9. Config Diff

必须支持：

```text
ADD
UPDATE
DELETE
ENABLE
DISABLE
NOOP
```

不推荐每次 Polling：

```text
clear all
 ↓
全部重新注册
```

因为这会增加：

```text
Timer 重建
执行竞态
重复执行风险
```

正确模型：

```text
DB Snapshot
     ↓
Runtime Snapshot
     ↓
Diff
     ↓
最小化 Apply
```

---

# 10. ADD

```text
DB 新增
 ↓
Validate
 ↓
Create Runtime Job
 ↓
Calculate nextRunAt
 ↓
Create Timer
```

Active Job 最终必须只有一个有效 Schedule Timer。

---

# 11. UPDATE

例如：

```text
15 → 20
```

正确流程：

```text
Clear Old Timer
 ↓
Update Definition
 ↓
Calculate New nextRunAt
 ↓
Create New Timer
```

核心不变量：

```text
一个 Active Job
=
一个有效 Schedule Timer
```

否则会出现：

```text
Job A
├── Timer #1
└── Timer #2
```

最终造成重复执行。

---

# 12. DELETE

当前采用软删除：

```text
deleted_at != NULL
```

Runtime 必须：

```text
Remove Runtime Job
 ↓
Clear Timer
 ↓
停止未来调度
```

如果任务已经执行：

```text
继续当前 Execution
```

不要因为删除配置就强制杀掉当前 Shell / HTTP。

但是：

```text
未来不再执行
```

---

# 13. DISABLE

```text
status = 0
```

处理：

```text
Clear Timer
 ↓
停止未来调度
```

当前正在执行的任务原则上继续完成。

---

# 14. ENABLE

```text
status = 1
```

处理：

```text
Validate
 ↓
Register
 ↓
Calculate nextRunAt
 ↓
Create Timer
```

默认：

```text
Enable ≠ Immediately Run
```

而是等待下一次合法 `nextRunAt`。

---

# 15. Timer 生命周期

核心不变量：

```text
Active Job
    = Exactly One Schedule Timer
```

特殊状态：

```text
Disabled Job
    = Zero Schedule Timer
```

```text
Deleted Job
    = Zero Schedule Timer
```

Worker Stop：

```text
All Job Timers = 0
```

---

# 16. with_block_lapping

字段：

```text
with_block_lapping
```

建议统一语义：

```text
0 = 允许重叠
1 = 不允许重叠
```

例如：

```text
expression = 15
duration = 20s
with_block_lapping = 1
```

预期：

```text
00 RUN
15 SKIP
30 RUN
45 SKIP
60 RUN
```

不能形成等待队列。

一个任务不能阻塞整个 Scheduler。

---

# 17. with_block_lapping Race Condition

最危险的路径：

```text
Coroutine A: check running = false
Coroutine B: check running = false
Coroutine A: running = true
Coroutine B: running = true
```

结果：

```text
同一 Job 同时存在两个 Execution
```

因此：

```text
check running
+
mark running
```

必须作为不可被并发打断的临界逻辑处理。

---

# 18. Execution Snapshot

数据库配置可以在任务运行过程中发生变化。

例如：

```text
command = old.sh
 ↓
Execution Start
 ↓
Web Admin 修改 command = new.sh
```

当前 Execution 必须继续：

```text
old.sh
```

下一轮：

```text
new.sh
```

因此 Execution 启动时应该保存本轮需要的 Task Definition Snapshot。

不能在一次 Execution 中途重新读取 Runtime 最新配置。

---

# 19. Trigger 完整流程

推荐统一为：

```text
Timer Trigger
      ↓
Check Job Exists
      ↓
Check status
      ↓
Check cron_between
      ↓
Check cron_skip
      ↓
Check with_block_lapping
      ↓
Create Execution Snapshot
      ↓
Generate exec_batch_id
      ↓
Executor
      ↓
Write Log
      ↓
Calculate nextRunAt
      ↓
Create Next Timer
```

需要特别保证：

```text
无论本轮 SUCCESS / FAILED / SKIPPED / Exception
```

都不能让 Scheduler 永久丢失下一次调度。

---

# 20. cron_between

表示允许执行的时间范围。

判断：

```text
Trigger
 ↓
cron_between
 ↓
Allowed?
```

如果不允许：

```text
SKIP
 ↓
Calculate Next Run
```

不修改原始：

```text
expression
```

---

# 21. cron_skip

表示禁止执行的时间范围。

推荐最终判断：

```text
allowed =
    inside(cron_between)
    &&
    !inside(cron_skip)
```

执行顺序：

```text
expression
 ↓
cron_between
 ↓
cron_skip
 ↓
concurrency
 ↓
executor
```

---

# 22. Executor

当前已经定义：

```text
exec_type = 1 → Shell
exec_type = 2 → HTTP
```

因此保持：

```text
Executor
├── Shell
└── HTTP
```

不为了“现代化”而提前加入更多执行类型。

---

# 23. Shell Executor

输入：

```text
command
```

例如：

```text
/bin/bash /home/wwwroot/swoolefy/Test/Python/shell.sh --type=1
```

执行生命周期：

```text
Create Process
 ↓
PID
 ↓
Wait / IO
 ↓
Exit Code
 ↓
Success / Failed
```

单个 Shell 失败必须隔离：

```text
Job Failure
≠
Worker Failure
```

---

# 24. HTTP Executor

使用已有字段：

```text
command
http_method
http_body
http_headers
http_request_time_out
```

映射：

```text
command → URL
http_method → Method
http_body → Body
http_headers → Headers
http_request_time_out → Timeout
```

Timeout 只影响当前 Execution：

```text
HTTP Timeout
 ↓
Execution Failed
 ↓
Scheduler Continue
```

---

# 25. cron_task_log

一次执行使用：

```text
exec_batch_id
```

串联本次 Execution 的日志。

例如：

```text
cron_id = 6
exec_batch_id = abc123
```

表示：

```text
Job 6
本轮 Execution = abc123
```

`pid` 对 Shell 任务应该尽量记录实际子进程 PID，而不是错误地把 Worker PID 当成任务 PID。

日志写入失败不应反向把 Worker 打崩。

---

# 26. Job Exception 隔离

错误模型：

```text
Job A
 ↓
Exception
 ↓
Worker Crash
 ↓
所有 Cron 停止
```

正确模型：

```text
Job A
 ↓
Catch
 ↓
Record Error
 ↓
Finish
 ↓
Schedule Next Run
```

同时：

```text
Job B
Job C
Job D
```

继续运行。

---

# 27. DB 故障

DB Polling 失败不能直接清空 Runtime。

正确：

```text
DB DOWN
 ↓
保留 Last Known Good Runtime
 ↓
已有任务继续运行
 ↓
下一轮重新同步
```

恢复：

```text
DB UP
 ↓
Load
 ↓
Diff
 ↓
Apply
```

尤其不能：

```text
Query Failed
 ↓
clear all jobs
```

否则数据库短暂抖动就会导致全部计划任务停止。

---

# 28. node_id

`node_id` 是业务 Cron 节点归属，不等同于：

```text
worker_id
coroutine_id
process_id
```

例如：

```text
Node 1
├── Task A
└── Task B

Node 2
├── Task C
└── Task D
```

Worker 查询：

```sql
WHERE node_id = :node_id
```

只负责自己的任务。

---

# 29. 多实例边界

当前 DB-driven 模型下：

```text
node_id = 1
```

如果同时运行：

```text
Worker A
Worker B
```

两个实例都可能执行同一任务。

因此当前部署模型应明确：

```text
一个 node_id
=
一个有效 Cron Scheduler 实例
```

第一阶段不增加：

```text
Redis
Etcd
ZooKeeper
Consul
Leader Election
分布式锁
```

这些属于后续分布式调度能力，不应与当前核心 Scheduler 混在一起。

---

# 30. Worker Start

启动：

```text
Worker Start
 ↓
Cron Manager Init
 ↓
Initial DB Sync
 ↓
Validate
 ↓
Register Jobs
 ↓
Create Timers
 ↓
Start Config Polling
```

初始同步成功后才建立有效 Schedule。

---

# 31. Worker Stop

停止：

```text
Worker Stop
 ↓
Stop Config Polling Timer
 ↓
Clear Job Timers
 ↓
Release Cron Runtime
```

Timer 清理必须是显式生命周期操作，不应只依赖对象析构。

---

# 32. Worker Restart

重点保证：

```text
Worker A
 ↓
Jobs + Timers
 ↓
Restart
 ↓
Worker B
```

最终：

```text
Old Runtime = 0
Old Timers = 0
New Runtime = DB Snapshot
New Timers = Expected
```

不能出现旧 Timer 与新 Timer 并存。

---

# 33. Misfire

第一阶段不实现复杂：

```text
Backfill
Catch-up
Misfire Policy
```

Worker 停止期间错过的任务，不无限补偿。

恢复后重新：

```text
Calculate Next Valid Run
```

避免：

```text
Worker Stop 1 Hour
 ↓
Restart
 ↓
瞬间执行大量历史任务
```

---

# 34. Timezone

Scheduler 必须使用统一时间基准。

需要保证：

```text
PHP Timezone
OS Timezone
DB Timezone
Cron Timezone
```

不会互相矛盾。

如果现有 Swoolefy Cron 已有时区定义，应继续复用现有定义，不再创建第二套 Timezone 配置体系。

---

# 35. Runtime Metrics

Swoolefy 已经实现 Runtime Metrics，因此 Cron 不应该重新设计第二套 Metrics 系统。

Cron 只接入现有 Runtime 能力。

建议重点统计：

```text
cron.jobs.total
cron.jobs.enabled
cron.jobs.running

cron.runs.total
cron.runs.success
cron.runs.failed
cron.runs.skipped
```

以及：

```text
execution.duration
```

---

# 36. Runtime Diagnostics

复用现有 Runtime Diagnostics。

Cron 至少应能通过现有诊断体系看到：

```text
Job Count
Enabled Count
Running Count
Last Config Sync
Last Config Sync Error
```

单任务：

```text
id
name
status
nextRunAt
lastRunAt
running
```

不再建立第二套 Worker 诊断系统。

---

# 37. P0 级问题

## P0-1：Timer 重复注册

重点检查：

```text
ADD
UPDATE
ENABLE
DISABLE
DELETE
Worker Restart
```

必须保证一个 Active Job 不会出现多个有效 Timer。

---

## P0-2：DELETE 后仍执行

```text
deleted_at != NULL
```

但旧 Timer 仍触发。

必须：

```text
Delete
 ↓
Clear Timer
```

---

## P0-3：DISABLE 后仍执行

```text
status = 0
```

不能继续产生未来 Execution。

---

## P0-4：Worker Restart 后重复 Timer

旧 Timer 必须彻底清理。

---

## P0-5：Job Exception 影响 Worker

单个 Job 的异常、Shell 错误、HTTP Timeout 不得导致 Cron Worker 停止。

---

## P0-6：DB 故障导致 Runtime 清空

必须保持：

```text
DB Failure
≠
Clear Runtime
```

---

## P0-7：with_block_lapping Race

必须保证：

```text
with_block_lapping = 1
```

时同一个 Job 在任意时刻最多一个 Running Execution。

---

# 38. P1

```text
Config Diff
Timer 生命周期统一
nextRunAt
Second Interval Scheduler
Linux Cron Scheduler
Expression Validation
cron_between
cron_skip
Execution Snapshot
Shell Executor
HTTP Executor
HTTP Timeout
exec_batch_id
Runtime Metrics 接入
Runtime Diagnostics 接入
```

---

# 39. P2

暂不进入核心生产闭环：

```text
Retry
Manual Run
Misfire / Backfill
Max Concurrent
增强 Execution History
Log Retention
Distributed Scheduler
Leader Election
```

原则：

> 先保证当前数据库驱动 Cron 的正确性，再增加增强能力。

---

# 40. 测试方案

## 40.1 Expression

秒级：

```text
15
20
25
```

必须验证：

```text
15s / 20s / 25s
```

Linux Cron：

```text
*/5 * * * *
0 * * * *
30 2 * * *
```

验证 `nextRunAt`。

---

## 40.2 Config Sync

覆盖：

```text
ADD
UPDATE
DELETE
ENABLE
DISABLE
```

---

## 40.3 Timer 生命周期

断言：

```text
ADD      → 1 Timer
UPDATE   → 1 Timer
DELETE   → 0 Timer
DISABLE  → 0 Timer
ENABLE   → 1 Timer
```

---

## 40.4 UPDATE 压力

反复修改：

```text
15
20
30
10
25
```

最终必须：

```text
Active Job Timer Count == 1
```

不能随着 Update 次数增加：

```text
1 → 2 → 3 → 4 → ...
```

---

## 40.5 with_block_lapping

```text
expression = 5
duration = 10s
with_block_lapping = 1
```

预期：

```text
00 RUN
05 SKIP
10 RUN
15 SKIP
20 RUN
```

---

## 40.6 Allow Overlap

```text
expression = 5
duration = 10s
with_block_lapping = 0
```

预期：

```text
00 Execution A
05 Execution B
10 Execution C
```

---

## 40.7 DB Down

```text
正常
 ↓
DB DOWN
 ↓
已有任务继续
 ↓
DB UP
 ↓
重新同步
```

断言：

```text
DB Down 期间 Runtime 不被清空
```

恢复后：

```text
Runtime 最终与 DB 配置一致
```

---

## 40.8 Worker Restart

```text
Worker A
 ↓
100 Jobs
 ↓
Restart
 ↓
Worker B
```

断言：

```text
无旧 Timer
无重复 Timer
无重复 Execution
```

---

## 40.9 Shell

覆盖：

```text
Success
Non-zero Exit
Exception
Long Running
```

---

## 40.10 HTTP

覆盖：

```text
200
400
404
500
Timeout
Connection Refused
Invalid URL
```

---

## 40.11 Execution Snapshot

```text
command = old.sh
 ↓
Execution Start
 ↓
UPDATE command = new.sh
```

断言：

```text
当前 Execution = old.sh
下一次 Execution = new.sh
```

---

# 41. 生产级核心不变量

整个 Cron 系统重点围绕以下不变量验收。

## 不变量 1

```text
Active Job
=
Exactly One Schedule Timer
```

## 不变量 2

```text
Disabled Job
=
Zero Schedule Timer
```

## 不变量 3

```text
Deleted Job
=
Zero Schedule Timer
```

## 不变量 4

```text
DB Failure
≠
Clear Runtime
```

## 不变量 5

```text
One Job
+
with_block_lapping = 1
=
At Most One Running Execution
```

## 不变量 6

```text
Job Exception
≠
Worker Exception
```

## 不变量 7

```text
Config Update
≠
Modify Current Execution Snapshot
```

---

# 42. Web Admin

未来 Web 后台只需要操作：

```text
cron_task
```

包括：

```text
Create
Update
Enable
Disable
Delete
```

后台不直接操作 Scheduler Timer。

完整链路：

```text
Web Admin
 ↓
MySQL
 ↓
Cron Worker Polling
 ↓
Config Diff
 ↓
Runtime
 ↓
Scheduler
```

这样 Web 管理后台与执行 Worker 完全解耦。

---

# 43. Web Admin 页面模型

任务列表：

```text
名称
节点
表达式
执行类型
状态
是否允许重叠
更新时间
```

任务详情：

```text
command
expression
cron_between
cron_skip
Shell / HTTP 参数
```

运行记录：

```text
cron_task_log
```

通过：

```text
cron_id
+
exec_batch_id
```

追踪一次完整 Execution。

---

# 44. 最终架构

```text
                         Web Admin
                             │
                             ▼
                        cron_task
                             │
                           MySQL
                             │
                        DB Polling
                             │
                             ▼
                    Config Sync / Diff
                             │
                             ▼
                    Runtime Job Registry
                             │
                             ▼
                         Scheduler
                             │
                ┌────────────┴────────────┐
                ▼                         ▼
        Second Interval              Linux Cron
           15/20/25                   */5 * * * *
                │                         │
                └────────────┬────────────┘
                             ▼
                         nextRunAt
                             │
                             ▼
                        Swoole Timer
                             │
                             ▼
                     Execution Guard
                             │
                             ▼
                    Execution Snapshot
                             │
                ┌────────────┴────────────┐
                ▼                         ▼
          Shell Executor            HTTP Executor
                │                         │
                └────────────┬────────────┘
                             ▼
                         Execution
                             │
                             ▼
                      cron_task_log
                             │
                  ┌──────────┴──────────┐
                  ▼                     ▼
           Runtime Metrics      Runtime Diagnostics
```

---

# 45. 实施顺序

## Phase 1：正确性/P0

```text
1. Timer 生命周期
2. ADD / UPDATE / DELETE
3. ENABLE / DISABLE
4. Worker Stop
5. Worker Restart
6. Job Exception 隔离
7. DB Failure 保留 Last Known Good Runtime
8. with_block_lapping Race Condition
```

## Phase 2：调度/P1

```text
1. Expression Parser
2. Second Interval
3. Linux Cron
4. nextRunAt
5. cron_between
6. cron_skip
```

## Phase 3：执行/P1

```text
1. Execution Snapshot
2. Shell Executor
3. HTTP Executor
4. Timeout
5. exec_batch_id
6. Log
```

## Phase 4：Runtime/P1

```text
1. Runtime Metrics
2. Runtime Diagnostics
```

直接复用 Swoolefy 已有 Runtime 能力，不重新建设监控体系。

## Phase 5：Web Admin

```text
1. Task CRUD
2. Enable / Disable
3. Task Detail
4. Execution Log
5. Runtime Status
```

---

# 46. 明确暂不引入的复杂设计

当前不需要为了“生产级”而引入：

```text
Redis
MQ
Etcd
ZooKeeper
Consul
分布式锁
Leader Election
独立 Scheduler Service
DAG
Workflow Engine
Timing Wheel
Calendar Queue
复杂 Priority Queue
```

原因：当前目标首先是让：

```text
DB
+
Worker
+
Scheduler
+
Executor
```

形成一个稳定、可验证、可管理的闭环。

---

# 47. 最终结论

Swoolefy Cron 最适合继续保持数据库驱动模型：

```text
             Web Admin
                 │
                 ▼
             cron_task
                 │
              MySQL
                 │
             DB Polling
                 │
                 ▼
            Config Sync
                 │
                 ▼
             Runtime
                 │
                 ▼
            Scheduler
                 │
        ┌────────┴────────┐
        ▼                 ▼
   15/20/25 秒级       Linux Cron
        │                 │
        └────────┬────────┘
                 ▼
             nextRunAt
                 │
                 ▼
            Swoole Timer
                 │
                 ▼
         Execution Guard
                 │
                 ▼
          Shell / HTTP
                 │
                 ▼
          cron_task_log
```

核心职责保持简单：

```text
cron_task
    ↓
要做什么

Scheduler
    ↓
什么时候做

Executor
    ↓
怎么做

Runtime
    ↓
现在是什么状态

cron_task_log
    ↓
发生了什么
```

最终生产级验收标准：

```text
ADD
    → 正确加入

UPDATE
    → 不产生重复 Timer

DELETE
    → 不再调度

DISABLE
    → 不再调度

ENABLE
    → 正确恢复

DB DOWN
    → 已加载任务继续运行

DB RECOVERY
    → 最终同步最新配置

Worker RESTART
    → 不残留旧 Timer

Job Exception
    → 不影响其他 Job

with_block_lapping
    → 不发生错误重叠

node_id
    → 不跨节点执行

expression = 15
    → 每 15 秒

Linux Cron
    → 按分钟级 Cron 规则

Shell
    → 独立执行

HTTP
    → 独立 Timeout

exec_batch_id
    → 可追踪一次 Execution
```

这套架构重点不是继续堆功能，而是把当前 Swoolefy Cron 的 **DB 配置 → Worker 同步 → Runtime → Scheduler → Executor → Log** 闭环做正确，并让未来 Web 管理后台只需要维护 `cron_task` 即可驱动整个系统。
