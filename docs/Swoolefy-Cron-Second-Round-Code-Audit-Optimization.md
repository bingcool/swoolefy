# Swoolefy Cron 第二轮代码审计与优化方案

> 基线：`swoolefy-6.2-x`
> 审计日期：2026-08-21
> 范围：`src/Worker/Cron` + `Test/Module/Cron`
> 原则：不新增大功能，不改变 DB Polling / ConfigDiff / RuntimeJobRegistry / Scheduler / Executor 主架构。
>
> 本文以 GPT-5.6 之前形成的《Swoolefy-Cron-Optimization-Production-Review》与《Swoolefy-Cron-Technical-Optimization》为基线，并再次对当前 GitHub 分支源码进行逐项核对。
>
> 重要结论：上一份技术优化文档中的“23 项已覆盖”不能直接等同于“当前代码已经完全落地”。本次源码复核发现，其中至少 O03/O04/O16/O19/O20 仍能在当前分支看到原实现或未闭环实现；另外 P0-2 仍存在明显风险。因此第二轮应以“验证已修复项 + 修复仍残留的正确性问题”为核心。

## 1. 当前架构判断

当前 Cron 主链路正确：

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
one-shot Timer
   ↓
ExecutionGuard
   ↓
ExecutionSnapshot
   ↓
ShellExecutor / HttpExecutor
   ↓
cron_task_log
```

当前代码已经具备 `ExecutionResult`、`ExecutionStatus`、`ExecutionSnapshot`、Runtime Diagnostics、RunOnce request 表、Retry、HTTP/Shell 两类 Executor 等能力。

因此：

- 不新增 MQ
- 不新增 Redis Scheduler
- 不新增 Leader Election
- 不新增第二套 Scheduler
- 不把 Local Cron 并入 DB Cron
- 不新增 DAG / Workflow
- 不新增执行类型

## 2. 本轮审计最重要的结论

### P0

| 编号 | 问题 | 当前判断 |
|---|---|---|
| P0-1 | 非法 DB Row 仍可能被 ConfigDiff 当作 DELETE | **确认仍存在** |
| P0-2 | Fork `proc_open` 仍未真正等待真实终态 | **确认仍存在** |
| P0-3 | stdout/stderr 仍存在 pipe drain 风险 | **确认仍存在** |

### P1

| 编号 | 问题 | 当前判断 |
|---|---|---|
| P1-1 | RunOnce 当前已支持 requestId 列表，但 CronManager 仍按 Job 去重消费 | **需要继续收敛** |
| P1-2 | Polling 仍对每个任务调用 `listPendingRunOnceIds()`，N+1 仍存在 | **确认仍存在** |
| P1-3 | `taskLogs()` 仍先分页后 status 过滤 | **确认仍存在** |
| P1-4 | HTTP timeout 仍存在 Worker 强制语义 | **确认仍存在** |
| P1-5 | `updated_at` 仍进入 fingerprint | **确认仍存在** |
| P1-6 | Dashboard/Trend 与 Stats 的 DB 聚合需要继续核验 | **部分已修，继续收敛** |
| P1-7 | `onTrigger()` arm 异常缺少当前调用点的最终兜底 | **需要修** |

### P2

| 编号 | 问题 | 当前判断 |
|---|---|---|
| P2-1 | Runner 静态实例生命周期 | 仍存在设计债务 |
| P2-2 | pid 文件等待仍使用原生 `sleep(1)` | 仍存在 |
| P2-3 | `listNodes()` N+1 | 仍存在 |
| P2-4 | batchSwitchStatus 非事务原子 | 仍存在 |
| P2-5 | 日志 retention | 后续 |
| P2-6 | 文档/代码契约同步 | 持续维护 |

---

# 3. P0-1：非法配置不能被解释为 DELETE

这是当前最应该立即修复的问题。

当前 `CronManager::applyRows()` 仍然是：

```php
foreach ($rows as $row) {
    try {
        $definition = TaskDefinition::fromArray($row);
    } catch (\Throwable $e) {
        $this->debug(...);
        continue;
    }

    $desired[$definition->jobId] = $definition;
}
```

问题在于：

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

非法 B 被 `continue` 后：

```text
desired:
A
C
```

然后：

```text
ConfigDiff
runtime B exists
desired B missing
        ↓
DELETE B
```

这与 Last Known Good 的设计原则冲突。

## 正确语义

必须区分：

```text
DB 查询失败
    → 保留全部 Runtime

DB 查询成功
    +
任务明确不存在
    → DELETE

DB 查询成功
    +
任务存在但非法
    → 保留旧 Runtime
```

## 当前代码中的误导点

代码已经存在：

```php
protectInvalidJobId()
```

但当前 `applyRows()` 的实际遍历路径没有把非法 Row 的 jobId 加入保护集合。

因此：

> “已经实现 protectInvalidJobId” ≠ “P0 已经修复”。

## 推荐修改

```php
$invalidJobIds = [];

foreach ($rows as $row) {
    if (!is_array($row)) {
        continue;
    }

    try {
        $definition = TaskDefinition::fromArray($row);
    } catch (\Throwable $e) {
        $this->protectInvalidJobId($row, $invalidJobIds);
        $this->debug(...);
        continue;
    }

    ...
}

$ops = $this->diff->diff(
    $this->registry->definitions(),
    $desired
);

foreach ($ops as $op) {
    if (
        $op['op'] === ConfigDiff::DELETE
        && isset($invalidJobIds[$op['jobId']])
    ) {
        continue;
    }

    $this->applyOp(...);
}
```

更推荐把“protected invalid”明确纳入 Diff 输入，而不是在 `applyOp()` 层临时判断。

## 验收

必须覆盖：

```text
合法 → 非法 → 合法
合法 → 删除
合法 → 禁用
合法 → DB 故障
非法新增任务
```

核心断言：

```text
非法已有任务：
Timer 不变
Runtime 不变
不会 DELETE
```

---

# 4. P0-2：Fork SUCCESS 仍不能等同于真实执行成功

当前 `CronForkProcess::executeCronSnapshot()`：

```php
$runner->procOpen(..., function (...) {
    ...
    $this->receiveCallBack(...);
});

return ExecutionResult::success(
    'cron_fork proc_open 已拉起',
    $pidHolder[0],
    0
);
```

这仍然是：

```text
proc_open 成功
    ↓
立即 SUCCESS
```

而不是：

```text
proc_open
    ↓
child running
    ↓
child exit
    ↓
exit code
    ↓
SUCCESS / FAILED
```

当前 `ExecutionResult` 虽然已经有：

```text
SUCCESS
FAILED
TIMEOUT
CANCELLED
pid
exitCode
httpStatus
```

但 `CronForkProcess` 的 proc_open 路径没有把真实 child exit code 传回来。

这说明：

> 数据模型已经准备好了，但 Fork Execution 生命周期还没有完全接上。

## 推荐最终模型

```text
CronManager
   ↓
ShellExecutor
   ↓
CronForkProcess
   ↓
CronForkRunner
   ↓
ProcessHandle
   ↓
wait + stdout/stderr drain
   ↓
ExecutionResult
```

不要新增 Scheduler。

## 必须保证

```text
exit 0  → SUCCESS
exit 1  → FAILED
exit 7  → FAILED
signal  → FAILED
timeout → TIMEOUT
```

而不是：

```text
fork 成功 → SUCCESS
```

---

# 5. P0-3：stdout/stderr 当前仍然存在 pipe deadlock 风险

当前 `CronForkRunner::procOpen()` 仍然建立：

```php
1 => ['pipe', 'w'], // stdout
2 => ['pipe', 'w'], // stderr
```

随后把 pipe 交给 callback：

```php
call_user_func_array($callable, $params);
```

但当前 `receiveCallBack()` 主要记录 PID：

```php
$this->logCronTaskRuntime(...);
```

并没有持续 drain stdout/stderr。

最后：

```php
foreach ($pipes as $pipe) {
    @fclose($pipe);
}

proc_close($proc_process);
```

这仍然存在：

```text
child
 ↓
stdout/stderr
 ↓
pipe buffer 满
 ↓
child 阻塞
 ↓
parent 等待
 ↓
Execution 卡住
```

## 正确实现

必须：

```text
proc_open
 ↓
关闭 stdin
 ↓
stdout non-blocking
stderr non-blocking
 ↓
持续 drain
 ↓
child exit
 ↓
EOF
 ↓
proc_close
 ↓
真实 exit code
```

即使输出达到上限：

```text
保存：
最多 N MB

但是：
继续 drain
```

不能：

```text
达到 N MB
 ↓
停止读取
```

否则仍然会死锁。

## 验收脚本

PHP：

```php
for ($i = 0; $i < 100000; $i++) {
    echo $i . PHP_EOL;
    fwrite(STDERR, "error={$i}" . PHP_EOL);
}
exit(7);
```

Python：

```python
import sys

for i in range(100000):
    print(i)
    print("error=" + str(i), file=sys.stderr)

sys.exit(7)
```

必须最终：

```text
Execution = FAILED
exit_code = 7
child = exited
FD = released
Worker = alive
```

---

# 6. P1-1：RunOnce 已经改进，但消费链路仍需要继续收敛

当前 `CronTaskService` 已经可以返回：

```php
run_once_request_ids
run_once_request_id
run_once_requested
```

并且 `ackRunOnce()` 已经改为：

```php
ackRunOnce(int $requestId)
```

这是正确方向。

但是 `CronManager::consumeRunOnceRequests()` 当前仍然：

```php
$seen[$definition->jobId]
```

以 Job 为单位去重。

因此同一任务：

```text
request 101
request 102
request 103
```

仍然不会在一次 Polling 中严格做到：

```text
101 → Execution
102 → Execution
103 → Execution
```

而是仍存在：

```text
Job 去重
 ↓
一次 runOnceNow
```

## 第二轮建议

`CronManager` 应直接消费：

```text
run_once_request_ids
```

而不是只消费：

```text
run_once_requested
```

逻辑：

```php
foreach ($requestIds as $requestId) {
    $result = $this->runOnceNow($jobId);

    if ($result->isCompleted()) {
        $runOnceAck($requestId);
    }
}
```

这样：

```text
requestId
    =
一次 Execution 的唯一消费单位
```

这是比 `seen[jobId]` 更正确的模型。

---

# 7. P1-2：RunOnce N+1 仍然存在

当前：

```php
fetchCronTask()
```

先：

```sql
SELECT * FROM cron_task ...
```

然后：

```php
foreach ($taskList as $item) {
    $this->listPendingRunOnceIds($item['id']);
}
```

1000 个任务：

```text
1 + 1000 SELECT
```

仍然成立。

## 建议

一次：

```sql
SELECT id, cron_id
FROM cron_task_run_request
WHERE consumed_at IS NULL
  AND cron_id IN (...)
ORDER BY id ASC
```

然后：

```php
[
    1 => [101, 102],
    3 => [103],
    8 => [104, 105, 106],
]
```

再注入 TaskDefinition。

这样保持：

```text
DB Polling
```

不改变架构，但把：

```text
O(N)
```

查询降为：

```text
O(1)
```

级别的数据库查询数量。

---

# 8. P1-3：taskLogs status 过滤仍然存在分页问题

当前仍然是：

```text
SQL LIMIT 20
 ↓
PHP classifyMessage()
 ↓
过滤 status
```

这是错误分页。

而且虽然 `taskStats()` 已经开始使用结构化 status，但 `taskLogs()` 仍然保留旧的 message 分类逻辑。

## 推荐

既然现在 `cron_task_log.status` 已经进入模型：

```sql
WHERE cron_id = ?
AND status = ?
```

然后：

```sql
ORDER BY id DESC
LIMIT ?, ?
```

不要再：

```php
ExecutionDetailDto::classifyMessage()
```

作为数据库查询条件。

---

# 9. P1-4：HTTP Timeout 仍存在语义不一致

当前 `CronTaskService`：

```php
if ($item['http_request_time_out'] < 120) {
    $cronHttpTask->request_time_out = 120;
}
```

因此：

```text
Admin 配置 30
      ↓
Worker 实际 120
```

这仍然存在。

而 `TaskDefinition` 同样把：

```text
<= 0
```

归一到：

```text
120
```

## 推荐统一规则

```text
0 / null
    ↓
默认 120

> 0
    ↓
严格使用用户配置
```

如果业务要求最小 1 秒：

```text
PayloadBuilder
    ↓
校验
    ↓
非法直接拒绝
```

不要：

```text
保存 30
实际执行 120
```

---

# 10. P1-5：updated_at 仍进入 fingerprint

当前 `TaskDefinition::fingerprint()` 明确包含：

```php
$this->updatedAt,
```

因此：

```text
修改 description
 ↓
updated_at 变化
 ↓
fingerprint 变化
 ↓
UPDATE
 ↓
clear Timer
 ↓
重新 arm
```

这没有必要。

## fingerprint 应只包含行为字段

例如：

```text
expression
execType
withBlockLapping
command
cronBetween
cronSkip
httpMethod
httpBody
httpHeaders
httpRequestTimeout
execBinFile
execScript
url
runType
forkType
argv
timezone
retry
```

而：

```text
name
description
updatedAt
```

不应该触发 Scheduler 重建。

---

# 11. P1-6：Dashboard / Trend 已经明显改善，但 Stats 需要统一

当前 `dashboardOverview()` 已经使用：

```text
ExecutionStatus
GROUP BY status
```

Trend 也已经使用：

```text
GROUP BY bucket,status
```

这是正确的。

但是 `taskStats()` 与 `taskLogs()` 仍需要确认是否完全使用同一 Execution 口径。

必须统一：

```text
Execution
=
exec_batch_id != ''
```

而：

```text
Definition Log
=
exec_batch_id = ''
```

不要混在一起。

最终：

```text
taskLogs
taskStats
dashboard
trend
execution detail
```

全部基于：

```text
ExecutionStatus
```

而不是 message。

---

# 12. P1-7：onTrigger 的 arm 仍需要最终兜底

当前：

```php
$job->timerId = 0;

if ($job->isSchedulable()) {
    $this->scheduler->arm($job, $planned);
}
```

如果：

```text
arm()
 ↓
Throwable
```

那么：

```text
job.timerId = 0
```

但是当前 trigger 本身不会重新 arm。

虽然已经存在：

```php
reconcileMissingTimers()
```

但必须确认它是否在每次 Polling 的成功路径中可靠执行。

第二轮应该明确：

```text
onTrigger arm 失败
 ↓
recordSchedulerError
 ↓
不影响 Worker
 ↓
下一次 Polling 必须 reconcile
```

并保证：

```text
Active Job
+
Timer = 0
```

不会长期存在。

---

# 13. P2：CronForkRunner 生命周期

当前仍然：

```php
protected static $instances = [];
```

并且：

```text
runnerName ≈ md5(cron_name)
```

虽然已有：

```php
removeRunner()
```

但 CronManager 的 DELETE / rename 并没有天然绑定 Runner 生命周期。

因此存在：

```text
删除任务
 ↓
Runtime 删除
 ↓
Runner 静态实例仍然存在
```

建议第二轮只做：

```text
Worker stop → remove all Runner
Task DELETE → 非 running 时 remove Runner
Task rename/id change → 不复用旧 Runner
```

不需要重构 Runner。

---

# 14. P2：pid 文件等待仍然阻塞

当前仍有：

```php
sleep(1);
```

并且最多 5 次。

在 Swoole Worker 中，这属于不应该存在的阻塞等待。

应该：

```php
System::sleep(0.1);
```

并使用 deadline：

```text
start
 ↓
deadline = now + 5s
 ↓
检查 pid file
 ↓
System::sleep(100ms)
 ↓
repeat
```

而不是：

```text
sleep(1) × 5
```

---

# 15. P2：listNodes 仍是 N+1

当前：

```php
foreach ($list as &$row) {
    $row['task_count'] =
        CronTaskEntity::queryNotDeleted()
            ->where('node_id', ...)
            ->count();
}
```

节点 N：

```text
1 + N queries
```

建议：

```sql
SELECT node_id, COUNT(*)
FROM cron_task
WHERE deleted_at IS NULL
GROUP BY node_id
```

一次完成。

---

# 16. P2：batchSwitchStatus 仍不是事务批量更新

当前：

```php
foreach ($dto->getIds() as $id) {
    $this->switchTaskStatus(...)
}
```

如果：

```text
1 success
2 success
3 DB error
```

结果：

```text
1 已修改
2 已修改
3 失败
```

API 却可能返回失败。

建议：

```text
begin transaction
 ↓
验证所有 id
 ↓
一次 UPDATE
 ↓
commit
```

这是数据一致性优化，不属于 Cron Engine P0。

---

# 17. 第二轮不应该修改的部分

以下设计继续保持：

```text
DB Polling
ConfigDiff
RuntimeJobRegistry
One-shot Timer
ExecutionGuard
ExecutionSnapshot
Local / Fork / URL 三种模式
Runtime Metrics
Runtime Diagnostics
Retry
RunOnce Request Table
```

尤其不要重新引入：

```text
Redis Scheduler
MQ
Leader Election
Distributed Lock
独立 Scheduler
```

当前架构已经不需要这些。

---

# 18. 第二轮实施顺序

## 第一批：P0 正确性

### ① Invalid Row → DELETE

必须首先修。

### ② Fork 真实终态

必须解决：

```text
proc_open
→ wait
→ exitCode
→ ExecutionResult
```

### ③ stdout/stderr drain

与 ② 同批。

因为两者实际上属于一个问题：

```text
Fork Execution Lifecycle
```

---

## 第二批：P1 数据路径

```text
① RunOnce requestId 真正逐条消费
② RunOnce N+1
③ taskLogs status 查询
④ taskStats / Dashboard / Trend 统一 Execution 口径
⑤ HTTP timeout
⑥ fingerprint
⑦ Timer reconcile
```

---

## 第三批：P2 工程收敛

```text
① Runner 生命周期
② pid file sleep
③ listNodes N+1
④ batchSwitchStatus transaction
⑤ log retention
⑥ 文档 / API 契约
```

---

# 19. 最重要的专项测试

第二轮不要只写普通 Unit Test。

## A. Invalid Config

```text
Runtime A/B/C
DB A/B/C
B → invalid
```

断言：

```text
A Timer = 1
B Timer = 1
C Timer = 1
```

不能：

```text
B DELETE
```

---

## B. Fork exit code

```text
exit 0
exit 1
exit 7
signal kill
```

断言：

```text
0 → SUCCESS
!=0 → FAILED
signal → FAILED
```

---

## C. stdout/stderr

```text
stdout 10MB
stderr 10MB
```

断言：

```text
child exits
exitCode correct
FD eventually 0
Worker alive
```

---

## D. RunOnce

```text
request 101
request 102
request 103
```

必须：

```text
101 → Execution A
102 → Execution B
103 → Execution C
```

并且：

```text
ack(101)
ack(102)
ack(103)
```

不能：

```text
ack(cron_id)
```

---

## E. Update while running

```text
Task A
 ↓
RUNNING
 ↓
UPDATE command
```

必须：

```text
当前 Execution → old Snapshot
下一 Execution → new Definition
```

---

## F. Delete while running

```text
RUNNING
 ↓
DELETE
```

必须：

```text
当前 Execution → 继续
Timer → 0
Execution 完成
Runtime Job → remove
```

---

# 20. 最终判断

这次重新对照当前分支以后，我对之前那份 GPT-5.6 文档的评价是：

> **架构判断正确，但“已覆盖”状态判断偏乐观。**

尤其当前源码仍能直接看到：

```text
CronForkProcess
    ↓
procOpen()
    ↓
立即 ExecutionResult::success()
```

以及：

```text
CronForkRunner::procOpen()
    ↓
pipe
    ↓
callback
    ↓
fclose
    ↓
proc_close
```

这两个地方说明 **Fork Execution Lifecycle 还没有真正闭环**。

同时：

```text
TaskDefinition::fingerprint()
```

仍包含 `updatedAt`，`CronTaskService` 仍对每个任务查询 pending RunOnce，`taskLogs()` 仍有 PHP 层 status 过滤，`fetchCronTask()` 仍然 `SELECT *`。

因此我不会把当前 Cron 判断为“已经全部优化完成”。

## 当前真正应该做的只有三个核心修复

```text
⭐⭐⭐⭐⭐ 1. Invalid Config 不得 DELETE
⭐⭐⭐⭐⭐ 2. Fork 必须拿到真实 exitCode
⭐⭐⭐⭐⭐ 3. stdout/stderr 必须持续 drain
```

这三个完成以后，再处理：

```text
⭐⭐⭐⭐ RunOnce requestId
⭐⭐⭐⭐ Polling N+1
⭐⭐⭐⭐ Execution 查询/统计
⭐⭐⭐  Timer / Runner 生命周期
```

这样最符合你一直坚持的原则：

> **不重新设计 Cron，只把现在这套 DB Polling → Scheduler → Execution 做正确、做稳定、做高负载下可持续。**
