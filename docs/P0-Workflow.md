# P0 Workflow Runtime CAS + Exception Isolation 代码级修改清单

> 基于当前 `swoolefy-6.2-x` 最新 `src.zip` 源码审计。
>
> 目标：**不新增业务功能，不引入分布式锁/Lease/自动重试**，只修复 Workflow Runtime 的状态并发覆盖与 Runtime 异常污染 Business Failure/Saga 的问题。

## 1. P0 目标

当前 Workflow 已经存在 `saveIfStatus()`，因此 HITL 的部分状态竞争已有保护；但普通 Runtime 状态更新仍存在普通 `save()`。

需要补齐两个边界：

```text
P0-1 Runtime State CAS

WorkflowRun
    revision = N
        │
        ├── 普通 Runtime mutation
        │       ↓
        │   saveIfRevision(N)
        │
        └── 状态迁移
                ↓
        saveIfStatusAndRevision(status, N)

成功 → revision N + 1
失败 → WorkflowRuntimeConflictException
             ↓
           立即 Abort
```

```text
P0-2 Runtime Exception Isolation

Throwable
├── Business Failure
│      ↓
│   Node FAILED
│      ↓
│   Saga（保持现有业务语义）
│
└── Runtime Failure
       ↓
   Runtime Abort
       ├── NO Node FAILED
       ├── NO Saga
       ├── NO Run FAILED
       └── NO stale save
```

## 2. 明确不做的事情

本轮严格控制范围：

- 不新增 Workflow Status。
- 不引入 Redlock / Distributed Lock。
- 不引入 Lease / Heartbeat。
- 不增加 Worker Crash Recovery。
- 不对 CAS Conflict 自动 Retry。
- 不改变现有 Business Failure / Saga 业务语义。
- 不机械地把所有 `runStore->save()` 替换成 CAS。
- 不修改 Workflow Definition `version` 的含义。
- 不把 `version` 当 Runtime Snapshot revision。

核心区分：

```text
version  = Workflow Definition Version
revision = Workflow Run Runtime Snapshot Version
```

## 3. 修改范围总览

| 优先级 | 位置 | 修改 |
|---|---|---|
| P0 | `WorkflowRun` | 增加 `revision` |
| P0 | `WorkflowRunSnapshot` | 持久化/恢复 `revision` |
| P0 | `RedisRunStore` | 增加 revision CAS + status/revision CAS |
| P0 | `WorkflowEngine` | 所有 Runtime mutation 按 CAS 分类 |
| P0 | `WorkflowEngine` | CAS Conflict 不得进入 Business Failure |
| P0 | `WorkflowEngine` | Runtime Exception 不得进入 Saga |
| P0 | `AbstractNode` | Runtime Exception 不得执行 `onFail()` |
| P0 | `SubWorkflowNode` | Runtime Exception 不得转成 Node FAILED |
| P0 | Resume rollback | 禁止普通 `save()` 覆盖最新 Snapshot |
| P0 | Terminal transition | 使用 status + revision CAS |
| P0 | 测试 | 增加 CAS conflict / exception isolation 回归测试 |

## 4. WorkflowRun 增加 revision

文件：

`src/Support/Workflow/WorkflowRun.php`

增加：

```php
private int $revision = 0;

public function getRevision(): int
{
    return $this->revision;
}

public function setRevision(int $revision): static
{
    $this->revision = $revision;

    return $this;
}
```

如果当前属性使用其他封装风格，保持项目现有风格即可。

## 5. revision 语义

初始 Run：

```text
revision = 0
```

每一次成功持久化 Runtime mutation：

```text
N → N + 1
```

例如：

```text
revision 0
    ↓
saveIfRevision(0)
    ↓
成功
    ↓
revision 1
```

`revision` 表示 Runtime Snapshot 的并发版本，不表示 Workflow Definition 版本。

## 6. WorkflowRunSnapshot 增加 revision

文件：

`src/Support/Workflow/Run/WorkflowRunSnapshot.php`

### fromRun()

增加：

```php
'revision' => $run->getRevision(),
```

### hydrate()

增加：

```php
$run->setRevision(
    (int) ($snapshot['revision'] ?? 0)
);
```

对于旧 Redis 数据，`revision` 缺失时可以兼容为 `0`；新版保存必须完整写入 `revision`。

## 7. RedisRunStore 增加 saveIfRevision()

文件：

`src/Support/Workflow/Run/RedisRunStore.php`

新增：

```php
public function saveIfRevision(
    WorkflowRun $run,
    int $expectedRevision
): bool
```

语义：

```text
Redis current revision == expectedRevision
        ↓
写入新 Snapshot
        ↓
revision + 1
```

否则：

```text
return false
```

## 8. Redis CAS 必须使用 Lua

禁止：

```php
$current = redis->get($key);

if ($currentRevision === $expectedRevision) {
    redis->set($key, $newValue);
}
```

因为 GET → PHP 判断 → SET 不是原子的。

正确方式：

```text
Lua
────────────────────
GET
 ↓
JSON decode
 ↓
检查 revision
 ↓
SET
 ↓
原子完成
```

具体 Redis API 参数形式按照当前项目 Redis abstraction 调整，不要绕过现有 Redis Component。

## 9. CAS 成功后的本地 revision

CAS 成功以后：

```php
$run->setRevision($expectedRevision + 1);
```

只有 Redis CAS 成功后才能修改本地 Run 的 revision。

不要提前：

```php
$run->setRevision($run->getRevision() + 1);
```

否则 CAS 失败后本地对象也会进入错误 revision。

## 10. 增加 saveIfStatusAndRevision()

当前已有：

```php
saveIfStatus()
```

增加：

```php
public function saveIfStatusAndRevision(
    WorkflowRun $run,
    WorkflowStatus $expectedStatus,
    int $expectedRevision
): bool
```

Redis Lua 一次性检查：

```text
status == expectedStatus
AND
revision == expectedRevision
```

成功：

```text
SET
revision = revision + 1
```

失败：

```text
return 0
```

必须是单次 Lua 原子操作。

## 11. 不要拆成两个 CAS

不要：

```text
saveIfStatus()
        ↓
saveIfRevision()
```

因为两个操作之间仍存在竞争窗口。

必须：

```text
status + revision
       ↓
     Lua
       ↓
atomic
```

## 12. Runtime Exception

推荐保持现有异常体系，只增加 Runtime 层：

```text
WorkflowException
├── Business / existing exceptions
│
└── WorkflowRuntimeException
    └── WorkflowRuntimeConflictException
```

如果当前已有合适的 Runtime/Infrastructure Exception，则复用，不重复造异常层级。

## 13. WorkflowRuntimeConflictException

建议携带：

```text
runId
workflowId
expectedRevision
```

例如：

```php
final class WorkflowRuntimeConflictException
    extends WorkflowRuntimeException
{
    public function __construct(
        string $runId,
        string $workflowId,
        int $expectedRevision
    ) {
        parent::__construct(
            sprintf(
                'Workflow runtime revision conflict: runId=%s, workflowId=%s, expectedRevision=%d',
                $runId,
                $workflowId,
                $expectedRevision
            )
        );
    }
}
```

日志不要打印整个 Workflow state。

## 14. WorkflowEngine：普通 Runtime mutation

文件：

`src/Support/Workflow/WorkflowEngine.php`

每次 Runtime mutation：

```php
$expectedRevision = $run->getRevision();

if (!$this->runStore->saveIfRevision(
    $run,
    $expectedRevision
)) {
    throw new WorkflowRuntimeConflictException(
        $run->getRunId(),
        $run->getWorkflowId(),
        $expectedRevision
    );
}
```

## 15. 必须 CAS 的 Runtime mutation

包括：

```text
state
currentNodeId
node output
executedNodeIds
lastRoutedEdge
pauseNodeId
error
Runtime metadata
```

原则：

> 已存在的 WorkflowRun，只要发生 Snapshot mutation，就属于 Runtime mutation。

## 16. Initial Run creation 可以保留 save()

创建 Run：

```text
new WorkflowRun
        ↓
revision = 0
        ↓
save()
```

可以继续普通 `save()`。

## 17. WAITING → RUNNING

当前已有：

```php
saveIfStatus($run, WorkflowStatus::WAITING)
```

升级为：

```php
$expectedRevision = $run->getRevision();

if (!$this->runStore->saveIfStatusAndRevision(
    $run,
    WorkflowStatus::WAITING,
    $expectedRevision
)) {
    throw new WorkflowRuntimeConflictException(...);
}
```

成功：

```text
revision N
    ↓
WAITING → RUNNING
    ↓
revision N+1
```

## 18. RUNNING → WAITING

例如 HITL Pause：

```text
RUNNING
  ↓
WAITING
```

必须使用：

```php
saveIfStatusAndRevision(
    $run,
    WorkflowStatus::RUNNING,
    $expectedRevision
)
```

不能：

```php
save($run);
```

## 19. RUNNING → COMPLETED

Terminal success：

```text
RUNNING
  ↓
COMPLETED
```

必须使用：

```php
saveIfStatusAndRevision(
    $run,
    WorkflowStatus::RUNNING,
    $expectedRevision
)
```

## 20. RUNNING → FAILED

只有 Business Failure 才允许：

```text
RUNNING → FAILED
```

使用：

```php
saveIfStatusAndRevision(
    $run,
    WorkflowStatus::RUNNING,
    $expectedRevision
)
```

Runtime Exception 不得走这里。

## 21. CANCEL WAITING / RUNNING

两者都升级为 status + revision CAS：

```text
WAITING + revision N
        ↓
CAS
        ↓
CANCELLED + revision N+1
```

以及：

```text
RUNNING + revision N
        ↓
CAS
        ↓
CANCELLED + revision N+1
```

如果 `_cancelRequested` 属于 Runtime state，也必须纳入同一次 CAS 保存。

## 22. Resume rollback 是 P0 必改点

当前 Resume 可能存在：

```text
WAITING
 ↓
CAS → RUNNING
 ↓
PauseNode::resume()
 ↓
失败
 ↓
rollback
 ↓
save()
```

这里的：

```php
save($run);
```

必须删除。

正确模型：

```text
CAS WAITING revision 10
        ↓
成功
        ↓
revision 11
        ↓
resume 失败
        ↓
CAS rollback
        ↓
WAITING revision 12
```

如果 rollback CAS 失败：

```text
WorkflowRuntimeConflictException
```

然后停止当前 Runtime。

## 23. Runtime Conflict 后禁止继续使用 stale Run

一旦：

```text
saveIfRevision() == false
```

或者：

```text
saveIfStatusAndRevision() == false
```

当前 `$run` 已经是 stale object。

之后禁止：

```php
$run->setState(...);
$runStore->save($run);
```

也禁止：

```text
继续 DAG
继续 Saga
继续 terminal transition
```

本 P0 不自动重新读取、不自动重试。

## 24. executeNodeOnce() 必须拆 Business / Runtime

当前核心问题类似：

```php
try {
    // execute node
} catch (\Throwable $e) {
    return NodeExecutionResult::failed($e);
}
```

不能继续让所有 Throwable 都成为 Node FAILED。

目标：

```text
Business exception
    ↓
NodeExecutionResult::failed()

WorkflowRuntimeException
    ↓
throw
```

如果现有异常体系允许，可采用：

```php
catch (WorkflowRuntimeException $e) {
    throw $e;
}

catch (Throwable $e) {
    return NodeExecutionResult::failed($e);
}
```

但最终必须确保只有明确的 Business Failure 才会转换为 Node FAILED。

## 25. AbstractNode 必须隔离 Runtime Exception

当前如果：

```php
catch (WorkflowException $e) {
    $this->onFail(...);
    throw $e;
}
```

而 Runtime Exception 又继承 `WorkflowException`，则 Runtime Conflict 会触发 `onFail()`。

应调整为：

```php
catch (WorkflowRuntimeException $e) {
    throw $e;
}

catch (WorkflowException $e) {
    $this->onFail(...);
    throw $e;
}
```

Runtime Exception：

```text
NO onFail()
NO Node FAILED
NO Saga
```

## 26. SubWorkflowNode 必须隔离

当前类似：

```php
try {
    $subRunId = $this->runner->run(...);
} catch (Throwable $e) {
    return NodeExecutionResult::failed(...);
}
```

增加 Runtime Exception 优先分支：

```php
catch (WorkflowRuntimeException $e) {
    throw $e;
}
```

避免：

```text
SubWorkflow Runtime Conflict
        ↓
Parent Node FAILED
        ↓
Saga
```

## 27. WorkflowEngine 外层异常边界

Engine 最外层必须保证：

```text
WorkflowRuntimeException
        ↓
直接传播 / Abort
```

不得进入：

```text
handleNodeFailure()
handleRunFailure()
SagaCoordinator
```

## 28. handleNodeFailure() 边界

`handleNodeFailure()` 只处理：

```text
Business Node Failure
```

防御性检查可以增加：

```php
if ($e instanceof WorkflowRuntimeException) {
    throw $e;
}
```

但核心是 Runtime Exception 在进入该方法之前就应被隔离。

## 29. handleRunFailure() 边界

当前风险：

```text
Throwable
 ↓
handleRunFailure()
 ↓
status = FAILED
 ↓
save()
```

必须阻断：

```text
WorkflowRuntimeException
        ↓
NOT handleRunFailure()
```

否则 Runtime Conflict 会重新产生 stale save。

## 30. SagaCoordinator 边界

Saga 只处理：

```text
Business Failure
```

不要让 Saga 自己判断 Runtime Conflict。

正确边界：

```text
Engine
 ↓
Exception 分类
 ↓
Business → Saga
Runtime → Abort
```

## 31. 最终 Exception Flow

```text
Business Exception
       │
       ↓
NodeExecutionResult::failed()
       │
       ↓
handleNodeFailure()
       │
       ↓
Saga（如果当前规则要求）
```

而：

```text
WorkflowRuntimeConflictException
       │
       ↓
throw
       │
       ↓
Engine Abort
       │
       ├── NO Node FAILED
       ├── NO onFail
       ├── NO Saga
       ├── NO Run FAILED
       └── NO stale save
```

## 32. 全量检查 runStore->save()

修改完成后重新搜索：

```text
runStore->save(
```

逐个分类：

```text
A. Initial creation
   → 可以普通 save

B. Runtime state mutation
   → saveIfRevision

C. State transition
   → saveIfStatusAndRevision

D. Runtime rollback
   → saveIfStatusAndRevision / saveIfRevision

E. 非持久化对象
   → 不涉及
```

不要做全局字符串替换。

## 33. CAS Conflict 日志

统一日志建议包含：

```text
workflow_runtime_conflict
run_id
workflow_id
workflow_version
expected_revision
```

不要记录完整 Workflow state。

## 34. CAS Conflict Metrics

如果已有 Runtime Metrics，可增加：

```text
workflow_runtime_cas_conflict_total
```

labels 只使用稳定低基数维度，例如 `workflow_id`；如果 workflow_id 数量不可控，则不增加动态 label。

不要使用：

```text
run_id
node_id
exception_message
state
```

作为 label。

## 35. Redis CAS Conflict 与 Redis 故障必须区分

```text
CAS == false
    ↓
正常 Runtime Conflict
```

而：

```text
Redis timeout
Redis connection error
Lua error
JSON decode failure
    ↓
Runtime Storage / Infrastructure Exception
```

不能把 Redis 故障伪装成 revision conflict。

## 36. 测试矩阵

### Revision 基础测试

```text
initial revision = 0
saveIfRevision(0) → success
revision = 1
saveIfRevision(0) → false
revision remains 1
```

### Status + Revision

```text
WAITING revision 5

saveIfStatusAndRevision(
    WAITING,
    5
)

→ success
→ revision 6
```

错误 revision：

```text
WAITING revision 6
expected revision 5
→ false
```

错误 status：

```text
RUNNING revision 5
expected WAITING
→ false
```

## 37. CAS 并发测试

两个 Runtime 同时读取：

```text
revision = 10
```

然后：

```text
A saveIfRevision(10)
B saveIfRevision(10)
```

结果必须：

```text
A = success
B = false
```

最终：

```text
revision = 11
```

不能：

```text
A = success
B = success
revision = 12
```

## 38. Runtime Conflict 不进入 Saga

测试：

```text
Runtime CAS conflict
```

断言：

```text
SagaCoordinator NOT called
```

并且：

```text
NodeExecutionResult::failed()
```

不得被构造。

## 39. Runtime Conflict 不进入 onFail()

测试：

```text
WorkflowRuntimeConflictException
```

断言：

```text
AbstractNode::onFail()
```

没有执行。

## 40. Runtime Conflict 不导致 Run FAILED

断言：

```text
Run status
```

不会被当前 Runtime 修改为：

```text
FAILED
```

更不能发生：

```text
save(stale run)
```

## 41. SubWorkflow Runtime Conflict

测试：

```text
SubWorkflow
   ↓
Runtime Conflict
```

断言：

```text
Parent Node FAILED = false
Saga = false
```

## 42. Resume rollback CAS

测试：

```text
revision 10
WAITING

resume
 ↓
WAITING → RUNNING
 ↓
revision 11

PauseNode::resume() throws
```

正常：

```text
RUNNING revision 11
 ↓
CAS rollback
 ↓
WAITING revision 12
```

如果另一个 Runtime 已经写入：

```text
revision 12
```

则 rollback：

```text
CAS false
 ↓
Runtime Conflict
```

绝对不能使用 plain `save()`。

## 43. Terminal CAS

至少覆盖：

```text
RUNNING → COMPLETED
RUNNING → FAILED
RUNNING → CANCELLED
WAITING → RUNNING
WAITING → CANCELLED
RUNNING → WAITING
```

每个迁移都验证：

```text
status expected
AND
revision expected
```

## 44. 回归测试

修改后必须运行：

```bash
php -l
```

并运行项目现有：

```text
Workflow tests
RedisRunStore tests
HITL tests
Saga tests
SubWorkflow tests
Coroutine tests
```

## 45. 实施顺序

```text
1. WorkflowRun
       ↓
   revision

2. WorkflowRunSnapshot
       ↓
   serialize / hydrate revision

3. RedisRunStore
       ↓
   saveIfRevision()
   saveIfStatusAndRevision()
   Lua atomic CAS

4. Runtime Exceptions
       ↓
   WorkflowRuntimeException
   WorkflowRuntimeConflictException

5. WorkflowEngine
       ↓
   普通 Runtime mutation CAS

6. WorkflowEngine
       ↓
   terminal/status transition CAS

7. Resume rollback
       ↓
   remove stale plain save()

8. AbstractNode
       ↓
   Runtime Exception isolation

9. SubWorkflowNode
       ↓
   Runtime Exception isolation

10. Engine failure boundary
       ↓
   Runtime != Business Failure

11. Tests
       ↓
   CAS + Exception Isolation
```

## 46. 最终代码语义

```text
                    WorkflowRun
                        │
                   revision = N
                        │
             ┌──────────┴──────────┐
             │                     │
       Runtime Mutation       State Transition
             │                     │
     saveIfRevision(N)    saveIfStatusAndRevision(
             │                     status, N
             │                   )
             │                     │
             └──────────┬──────────┘
                        │
                    Redis Lua
                        │
              ┌─────────┴─────────┐
              │                   │
            success             conflict
              │                   │
          revision N+1       RuntimeConflict
                                  │
                                Abort
```

异常边界：

```text
                   Throwable
                       │
              ┌────────┴────────┐
              │                 │
          Business          Runtime
              │                 │
              ↓                 ↓
        Node FAILED          throw
              │                 │
              ↓                 ↓
            Saga              Abort
                                │
                     ┌──────────┼──────────┐
                     ↓          ↓          ↓
                  no Node     no Saga   no stale save
                    FAILED
```

## 47. P0 验收标准

- [ ] `WorkflowRun` 有 `revision`。
- [ ] Snapshot 能持久化/恢复 `revision`。
- [ ] 新 Run 从 revision `0` 开始。
- [ ] Redis 有 atomic `saveIfRevision()`。
- [ ] Redis 有 atomic `saveIfStatusAndRevision()`。
- [ ] CAS 成功后 revision 严格 `N → N+1`。
- [ ] CAS Conflict 不会覆盖 Redis 中的新 Snapshot。
- [ ] 所有 Runtime state mutation 已从普通 `save()` 改为 CAS。
- [ ] 所有 Runtime status transition 已使用 status + revision CAS。
- [ ] Resume rollback 不再使用普通 `save()`。
- [ ] CAS Conflict 后当前 Runtime 立即 Abort。
- [ ] CAS Conflict 后不继续执行 DAG。
- [ ] CAS Conflict 不进入 Node FAILED。
- [ ] CAS Conflict 不执行 `onFail()`。
- [ ] CAS Conflict 不进入 Saga。
- [ ] CAS Conflict 不进入 `handleRunFailure()`。
- [ ] CAS Conflict 不把 Run 设置为 FAILED。
- [ ] SubWorkflow Runtime Exception 不被转换为 Node FAILED。
- [ ] Runtime Exception 不触发 Saga。
- [ ] Runtime Exception 不发生 stale Run save。
- [ ] 并发 CAS 测试通过。
- [ ] HITL Resume/CANCEL 回归测试通过。
- [ ] Saga 回归测试通过。
- [ ] SubWorkflow 回归测试通过。
- [ ] PHP 8.4 语法检查通过。

## 48. 最终结论

本轮不要继续扩展 Workflow 功能。

当前最关键的是把：

```text
status CAS
```

升级为：

```text
status + revision CAS
```

并同时建立：

```text
Business Failure
        ≠
Runtime Failure
```

两个边界。

最终保证：

```text
Runtime stale
    ↓
CAS conflict
    ↓
Abort
```

而不是：

```text
Runtime stale
    ↓
Node FAILED
    ↓
Saga
    ↓
Run FAILED
    ↓
save(stale)
```

**后者是当前最需要消除的错误链路。**

本方案只针对当前源码中已经存在的 Runtime 状态持久化与异常处理路径进行修复，不引入新的分布式协调机制，不扩大 Workflow 功能边界。
