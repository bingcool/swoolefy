# P0 Workflow Runtime CAS + Exception Isolation 代码级修改清单

> 对照当前仓库 `src/Support/Workflow/**`（PHP 8.4）修订。  
> 原稿基于过期目录（`WorkflowRun.php` / `Run/RedisRunStore.php` / `WorkflowStatus`），不能按原文落地。  
> 目标不变：**不新增业务功能，不引入分布式锁 / Lease / 自动重试**；只修 Runtime 快照并发覆盖，以及 Runtime 异常污染 Business Failure / Saga。

## 0. 相对原稿：哪些合理、哪些必须改

**目标与边界仍然合理**，应当保留：

- `version`（定义版本）与 `revision`（Run 快照并发版本）分离；
- Runtime mutation 用 revision CAS；状态迁移用 **status + revision 同一次原子 CAS**；
- CAS 失败立即 Abort，不重试、不 Saga、不 Node FAILED、不 stale `save()`；
- 禁止 GET→PHP 判断→SET；Redis 必须 Lua，DB 必须单条 `UPDATE … WHERE`。

**必须按现码修正**（否则会改错文件、改错类型、漏掉存储后端）：

| 原稿 | 现码 |
|------|------|
| `src/Support/Workflow/WorkflowRun.php` | `src/Support/Workflow/Engine/WorkflowRun.php` |
| `src/Support/Workflow/Run/WorkflowRunSnapshot.php` | `src/Support/Workflow/Engine/WorkflowRunSnapshot.php` |
| `src/Support/Workflow/Run/RedisRunStore.php` | `src/Support/Workflow/Engine/RedisRunStore.php` |
| `src/Support/Workflow/WorkflowEngine.php` | `src/Support/Workflow/Engine/WorkflowEngine.php` |
| `WorkflowStatus` | `RunStatus`（backed enum） |
| `$run->getWorkflowId()` / getter 风格 | `$run->compiled->workflowId()`；Run 字段是 **构造提升 public 属性** |
| Snapshot `fromRun()` 里塞 `'revision' =>` 数组 | 快照是 **readonly 构造对象**：`fromRun` / `toArray` / `fromArray` / `hydrate(Registry)` |
| 只改 Redis | **必须同步** `RunStoreInterface`、`InMemoryRunStore`、`DbRunStore` |
| 从零写 Lua | 已有 `saveIfStatus` 的 `CAS_LUA` + `evalScript()`（Predis / phpredis 参数不同） |
| 未提 resume 回滚外的 stale save | `executeFromNode` 每节点 `save()`、`handleRunFailure`、`applyCancellationIfRequested` 都是 P0 |

## 1. P0 目标

HITL 已有 `saveIfStatus()`（Lua / SQL `WHERE status=`），能挡住 double-resume / cancel 竞态；**同 status 下的字段覆盖仍走无条件 `save()`**。

补齐两个边界：

```text
P0-1 Runtime State CAS

WorkflowRun.revision = N
    ├── 普通 Runtime mutation → saveIfRevision(N)
    └── 状态迁移           → saveIfStatusAndRevision(expectedStatus, N)

成功 → 持久化层 revision = N+1，且仅成功后改本地 $run->revision
失败 → WorkflowRuntimeConflictException → 立即 Abort
```

```text
P0-2 Runtime Exception Isolation

Throwable
├── Business Failure（节点业务失败、Timeout 等现有语义）
│      → Node FAILED → Saga（保持现有业务语义）
└── Runtime Failure（CAS conflict / 存储基础设施）
       → Abort：NO Node FAILED / NO onFail / NO Saga / NO Run FAILED / NO stale save
```

`WorkflowTimeoutException` **继续当业务/节点失败**（现继承 `WorkflowException`，由 TimeoutGuard 抛出）。不要划进 Runtime Abort。

## 2. 明确不做

- 不新增 `RunStatus`。
- 不引入 Redlock / Lease / Heartbeat / Worker Crash Recovery。
- 不对 CAS Conflict 自动 Retry、不自动 `find()` 重读再跑 DAG。
- 不改变 Business Failure / Saga 语义。
- 不机械全局替换 `runStore->save()`。
- 不修改 Definition `version` 含义，不用 `version` 当 snapshot revision。
- 不把 `saveIfStatus()` 从接口删掉（现有 HITL / Redis CAS 单测仍用）；Engine 新路径改走 status+revision CAS。

```text
version  = CompiledWorkflow / Registry 定义版本
revision = WorkflowRun 持久化快照的乐观并发版本
```

## 3. 修改范围总览

| 优先级 | 位置 | 修改 |
|--------|------|------|
| P0 | `Engine/WorkflowRun.php` | 增加 `public int $revision = 0` |
| P0 | `Engine/WorkflowRunSnapshot.php` | `fromRun` / `toArray` / `fromArray` / `hydrate` 读写 `revision`；缺省兼容 0 |
| P0 | `Engine/RunStoreInterface.php` | 增加 `saveIfRevision`、`saveIfStatusAndRevision` |
| P0 | `Engine/RedisRunStore.php` | 扩展现有 Lua：比对 revision / status+revision；TTL/WAITING 逻辑保持 |
| P0 | `Engine/DbRunStore.php` | `UPDATE … WHERE status AND revision`；表增加 `revision` 列 |
| P0 | `Engine/InMemoryRunStore.php` | 与 Redis/DB 同等 CAS 语义（`persistedRevision`） |
| P0 | `Schema/workflow_runs.sql` + `Tests/WorkflowRunsSchemaInstaller.php` | 建表/SQLite 单测表加 `revision` |
| P0 | Exception | `WorkflowRuntimeException` / `WorkflowRuntimeConflictException` |
| P0 | `Engine/WorkflowEngine.php` | mutation/transition 分类 CAS；外层隔离 Runtime |
| P0 | `Node/AbstractNode.php` | Runtime 不走 `onFail()` |
| P0 | `Node/SubWorkflowNode.php` | Runtime 不转 Node FAILED |
| P0 | 测试 | Store CAS + Engine 隔离 + 更新 `PauseNodeIdSpyRunStore` |

## 4. WorkflowRun 增加 revision

文件：`src/Support/Workflow/Engine/WorkflowRun.php`

该类是 **构造提升 public 属性**，不要突然改成 private + getter 两套风格。增加：

```php
/** Runtime 快照乐观锁版本；与 CompiledWorkflow::version() 无关。 */
public int $revision = 0,
```

可另加 `getRevision(): int` 仅为调用点方便，但 Engine / Snapshot 以 `$run->revision` 为准。

新 Run：`start()` 里 `new WorkflowRun(...)` 不传则 `revision = 0`。

## 5. revision 语义

```text
新 Run revision = 0
每次 CAS 成功：持久化 N → N+1，然后 $run->revision = $expectedRevision + 1
CAS 失败：禁止改本地 revision，禁止再 save($run)
```

旧 Redis/DB payload 无 `revision` 字段：hydrate 为 `0`。

## 6. WorkflowRunSnapshot

文件：`src/Support/Workflow/Engine/WorkflowRunSnapshot.php`

- 构造增加 `public readonly int $revision = 0`
- `fromRun()`：`revision: $run->revision`
- `toArray()`：`'revision' => $this->revision`
- `fromArray()`：`(int) ($payload['revision'] ?? 0)`
- `hydrate()`：`new WorkflowRun(..., revision: $this->revision)`

`version` 字段继续表示定义版本，禁止复用。

## 7. RunStoreInterface

文件：`src/Support/Workflow/Engine/RunStoreInterface.php`

现接口只有 `save` / `saveIfStatus` / `find`。P0 **必须扩接口**，三套实现 + 测试 Spy 一起改。

```php
public function save(WorkflowRun $run): void;

/** 仅当持久化 revision == $expectedRevision 时写入，成功后持久化 revision 为 expected+1 */
public function saveIfRevision(WorkflowRun $run, int $expectedRevision): bool;

/**
 * 仅当持久化 status == $expectedStatus 且 revision == $expectedRevision 时写入。
 * 禁止拆成 saveIfStatus + saveIfRevision 两次调用。
 */
public function saveIfStatusAndRevision(
    WorkflowRun $run,
    RunStatus $expectedStatus,
    int $expectedRevision,
): bool;

public function saveIfStatus(WorkflowRun $run, RunStatus $expectedStatus): bool;

public function find(string $runId): ?WorkflowRun;
```

`saveIfStatus` **保留**：语义仍是只比 status（现 HITL 测试：`RedisRunStoreCasTest`、`WorkflowHitlAuthTest`）。  
**Engine 业务路径不要再靠它做状态迁移**，避免「status 相同、字段已被别人改」的丢失更新。

返回值约定：

- `true` / `false`：CAS 成功 / **条件不匹配**（conflict）
- Redis 超时、连接失败、Lua 执行失败、JSON 损坏：**抛异常**，不得 `return false`

## 8. RedisRunStore：在现有 Lua 上扩展

文件：`src/Support/Workflow/Engine/RedisRunStore.php`

已有：

- `CAS_LUA`：GET → `cjson.decode` → `data.status ~= ARGV[1]` → SET/SETEX
- `ttlFor()`：`WAITING` 强制 TTL=0（`SET` 清旧过期），其它用 `ttlSeconds`
- `evalScript()`：Predis `eval($script, $numKeys, ...$args)` vs phpredis `eval($script, $keysAndArgs, $numKeys)`
- Redis 异常已包成 `WorkflowException` —— P0 改为 **`WorkflowRuntimeException`**（基础设施，不进 Saga）

**禁止**再写一套 GET+PHP+SET。

新增两段 Lua（或参数化一段），必须保留 WAITING TTL 行为。

### 8.1 返回码（修现码缺陷）

现 `CAS_LUA` 在 `cjson.decode` 失败时 `return 0`，会伪装成 status conflict。P0 必须区分：

```text
1  = CAS 成功
0  = key 不存在或条件不匹配（正常 conflict）
负数或抛错 = JSON 损坏 / 非 table（基础设施错误 → 抛 WorkflowRuntimeException）
```

PHP 侧：`eval` 抛错 → Runtime 异常；返回 `0` → `false`；返回 `1` → `true` 且 `$run->revision = $expectedRevision + 1`。

写入 payload 前由 PHP `json_encode(WorkflowRunSnapshot::fromRun($run)->toArray())`；Lua 成功后才 bump 本地 revision。

### 8.2 saveIfRevision

```text
GET → decode
tonumber(data.revision or 0) == expectedRevision
→ SET/SETEX 新 JSON（内含 revision+1）
```

### 8.3 saveIfStatusAndRevision

```text
data.status == expectedStatus
AND tonumber(data.revision or 0) == expectedRevision
→ 同一次 Lua SET
```

不要：

```text
saveIfStatus(); saveIfRevision();
```

## 9. CAS 成功后才改本地 revision

```php
if (!$this->runStore->saveIfRevision($run, $expected)) {
    throw new WorkflowRuntimeConflictException(...);
}
$run->revision = $expected + 1;
```

或由 Store 在返回 true 前写 `$run->revision`。禁止 CAS 前 `$run->revision++`。

payload 里的 revision 必须已是 N+1（PHP 组 JSON 时用 `$expected + 1`，或 Lua 改 JSON）。推荐 PHP 组 snapshot 时写入 N+1，Lua 只负责条件判断 + SET，避免 Lua 改 JSON 与 PHP 对象不一致。

推荐实现：

```text
$toPersist = clone 语义：snapshot.revision = expected + 1
Lua 只比较 Redis 里的旧 revision
成功后再 $run->revision = expected + 1
```

`WorkflowRun` 是引用对象，不要在比对失败后留下 N+1。

## 10. DbRunStore

文件：`src/Support/Workflow/Engine/DbRunStore.php`  
Schema：`src/Support/Workflow/Schema/workflow_runs.sql`  
SQLite 单测：`src/Support/Workflow/Tests/WorkflowRunsSchemaInstaller.php`

现 CAS：`UPDATE … WHERE run_id=? AND status=:expected_status`，`rowCount>0`。

P0：

1. 表增加 `revision INT NOT NULL DEFAULT 0`（查询列，不要只藏在 payload）。
2. `save()` UPSERT 同时写 `revision` 列与 payload。
3. `saveIfRevision`：`WHERE run_id=? AND revision=:expected AND deleted_at IS NULL`
4. `saveIfStatusAndRevision`：`WHERE run_id=? AND status=:expected_status AND revision=:expected`
5. 死锁重试 **最多 3 次** 的现逻辑可保留，只针对锁等待；**条件不匹配（rowCount=0）不得重试**（否则会把 conflict 打成成功）。
6. PDO/SQL 错误抛 `WorkflowRuntimeException`，不要 `WorkflowException`。
7. 提供生产 `ALTER TABLE` 说明（DEFAULT 0 兼容旧行）；单测 installer 直接建新列。

## 11. InMemoryRunStore

文件：`src/Support/Workflow/Engine/InMemoryRunStore.php`

现用 `$persistedStatus` 模拟 DB status。P0 增加 `$persistedRevision`：

- `save()`：覆盖 run，并记下 status + revision（若调用方已 bump，以对象为准；初始 0）
- `saveIfRevision` / `saveIfStatusAndRevision`：比对 **已持久化** 的 revision/status，成功后再写入并把 persistedRevision+1
- `find()` 单测返回同一引用：CAS 测试必须像 Redis 测试那样 **两份 WorkflowRun 快照**（改字段后分别 CAS），不能两人改同一对象再比 revision

`PauseNodeIdSpyRunStore`（`WorkflowHitlAuthTest.php` 文件底部）实现了 `RunStoreInterface`，接口扩方法后必须转发新 CAS API。

## 12. 异常体系

目录：`src/Support/Workflow/Exception/`

现有：`WorkflowException` ← `WorkflowTimeoutException` / `Compile` / `Permission` / `RateLimit`。

新增：

```text
WorkflowException
├── （现有业务/编译/超时/限流）
└── WorkflowRuntimeException          // 基础设施 / 并发控制
    └── WorkflowRuntimeConflictException
```

Runtime **继承 WorkflowException**，因此 `AbstractNode::run()` 必须 **先 catch Runtime 再 catch WorkflowException**，否则 conflict 会 `onFail()`。

`WorkflowRuntimeConflictException` 构造参数：

```text
runId
workflowId          // $run->compiled->workflowId()
expectedRevision
```

日志字段：`workflow_runtime_conflict`、`run_id`、`workflow_id`、`workflow_version`（定义 version）、`expected_revision`。  
**不要**打完整 state / nodeOutputs。

存储层失败（超时、Lua、JSON 损坏）：`WorkflowRuntimeException`，**不要**伪装成 conflict（`false`）。

## 13. WorkflowEngine：现有 save() 分类

文件：`src/Support/Workflow/Engine/WorkflowEngine.php`

当前调用（必须逐条归类，禁止全局替换）：

| 位置 | 现行为 | P0 |
|------|--------|-----|
| `start()` 首次 | `save($run)` revision=0 | **保留 save()**（创建） |
| `start()` RUNNING→COMPLETED | `save()` | `saveIfStatusAndRevision(RUNNING, N)` |
| `start()` catch → `handleRunFailure` | `save()` | Runtime 不得进入；Business 用 status+revision CAS |
| `resume()` WAITING→RUNNING | `saveIfStatus(WAITING)` | **升级** `saveIfStatusAndRevision(WAITING, N)` |
| `resume()` PauseNode::resume 失败回滚 | **`save()`** | **必须改 CAS**（原稿 P0 必改点，现码仍在） |
| `resume()` 后 state / COMPLETED | `save()` | mutation → `saveIfRevision`；COMPLETED → status+revision |
| `resume()` catch `handleRunFailure` | `save()` | 同 start |
| `cancel()` WAITING | `saveIfStatus(WAITING)` | 升级 status+revision |
| `cancel()` RUNNING（写 `_cancelRequested`） | `saveIfStatus(RUNNING)` | 升级；`_cancelRequested` 同一次 CAS 写入 |
| `cancel()` 其它非终态 | `save()` | status+revision（兜底） |
| `executeFromNode` 每节点前 | `save()` 写 currentNodeId | `saveIfRevision` |
| `executeFromNode` WAITING | `save()` | `saveIfStatusAndRevision(RUNNING, N)` |
| `handleNodeFailure` 非 Saga FAILED | `save()` | Business only + status+revision |
| `runCompensation` COMPENSATING/结果 | `save()` | Business Saga 路径；用 revision CAS，失败则 Runtime Abort **不要再 Saga 一遍** |
| `applyCancellationIfRequested` | `find()` 后 **`save($run)`** | **高危 stale 覆盖**：本地 $run 可能落后于 fresh；应基于 fresh 的 revision 做 CAS，或只把本地 status 对齐 **禁止无条件 save 旧对象** |

CAS 失败：抛 `WorkflowRuntimeConflictException`，**停止当前 Runtime**。

创建以外的「已存在 Run 的 Snapshot mutation」都算 Runtime mutation。

## 14. WAITING ↔ RUNNING / 终态

类型用 `RunStatus::WAITING` 等，不是 `WorkflowStatus`。

```text
WAITING + rev N  --resume-->  RUNNING + N+1     saveIfStatusAndRevision(WAITING, N)
RUNNING + rev N  --HITL-->    WAITING + N+1     saveIfStatusAndRevision(RUNNING, N)
RUNNING + rev N  --success--> COMPLETED + N+1   saveIfStatusAndRevision(RUNNING, N)
RUNNING + rev N  --business--> FAILED + N+1     仅 Business Failure
WAITING|RUNNING + rev N --cancel--> CANCELLED   saveIfStatusAndRevision(当前 status, N)
```

Runtime Exception **不得** RUNNING→FAILED。

## 15. Resume rollback（现码 L185–191）

现码：

```text
CAS WAITING → RUNNING
PauseNode::resume() 失败
save() 回滚 WAITING   // 无条件覆盖
```

改为：

```text
CAS WAITING rev 10 → RUNNING rev 11
resume 失败
CAS RUNNING rev 11 → WAITING rev 12
失败 → WorkflowRuntimeConflictException，禁止 save()
```

## 16. executeNodeOnce() 拆 Business / Runtime

现码（约 L452–454）把 **所有 Throwable** 变成 `NodeExecutionResult::failed()`，conflict 会进 `handleNodeFailure` → Saga。

```php
} catch (WorkflowRuntimeException $e) {
    throw $e;
} catch (Throwable $e) {
    $result = NodeExecutionResult::failed($e);
}
```

只有明确的业务失败才转为 Node FAILED。Runtime 在转 failed / `fireNodeFail` **之前**抛出。

## 17. AbstractNode

文件：`src/Support/Workflow/Node/AbstractNode.php`

现 `catch (WorkflowException)` 与 `catch (Throwable)` 都会 `onFail()`。

```php
} catch (WorkflowRuntimeException $e) {
    throw $e;
} catch (WorkflowException $e) {
    $this->onFail($ctx, $state, $e);
    throw $e;
} catch (Throwable $e) {
    $this->onFail($ctx, $state, $e);
    throw $e;
}
```

Runtime：NO `onFail` / NO Node FAILED / NO Saga。

## 18. SubWorkflowNode

文件：`src/Support/Workflow/Node/SubWorkflowNode.php`

现 `runner->run()` 的任意 Throwable 都 `NodeExecutionResult::failed()`。

```php
} catch (WorkflowRuntimeException $e) {
    throw $e;
} catch (Throwable $e) {
    return NodeExecutionResult::failed(...);
}
```

避免：子 Run CAS conflict → 父节点 FAILED → 父 Saga。

`onResume` 里 `$engine->resume($subRunId)` 若抛 Runtime，同样上抛，不要包成业务 `WorkflowException`。

## 19. Engine 外层与 handleNodeFailure / handleRunFailure / Saga

`start()` / `resume()` 的 `catch (Throwable $e) { $this->handleRunFailure($run, $e); }` 必须：

```php
} catch (WorkflowRuntimeException $e) {
    // 不改 FAILED、不再 save、可选 fireRunComplete 仅当 run.start 已占槽且不会双计
    throw $e;
} catch (Throwable $e) {
    $this->handleRunFailure($run, $e);
    throw $e;
}
```

**Plugin 槽位**：`fireRunStart` 已成功时，Runtime Abort 仍可能需要 `fireRunComplete` 防 RateLimit 泄漏；这不是「Run FAILED」，不要顺带 `save(stale)`。若 CAS 从未成功写入本 Runtime 的新快照，complete 钩子只释放本进程槽位。

`handleNodeFailure()` / `handleRunFailure()` 入口防御：

```php
if ($e instanceof WorkflowRuntimeException) {
    throw $e;
}
```

`SagaCoordinator` 不判断 conflict；只有 Engine 分类后的 Business 才 `runCompensation()`。

## 20. applyCancellationIfRequested

每次节点前 `find()` 最新快照是对的（跨 Worker cancel）。  
错误的是随后 `$this->runStore->save($run)` 把 **可能过期的内存 $run** 写回。

P0：

- 发现 `fresh->status === CANCELLED`：只对齐内存 `$run->status`，**不要 save 旧对象**（存储已是 CANCELLED）；
- 发现 `_cancelRequested`：用 **fresh.revision** 做 `saveIfStatusAndRevision(RUNNING, fresh.revision)` 写成 CANCELLED；conflict 则 Abort。

## 21. Metrics

已有 `Plugin/Builtin/MetricsPlugin`（内存计数，无 Prometheus label）。  
P0 **不要**给 conflict 打 `run_id`/`node_id` 高基数。可选：`status_counts` 增加 `runtime_conflict` 或插件内一个整数计数。没有现成 counter 就记日志，不为此引入新遥测系统。

## 22. 测试

现成相关用例（改接口后要跟着绿）：

- `PHPUintTest/Unit/Support/Workflow/RedisRunStoreCasTest.php`
- `PHPUintTest/Unit/Support/Workflow/WorkflowHitlAuthTest.php`（含 `PauseNodeIdSpyRunStore`）
- `WorkflowRunStoreTest.php`、`WorkflowPhase1~4`、`WorkflowIntegrationTest.php`、Saga / HITL / SubWorkflow 模块测

新增：

1. revision 0→1；旧 revision CAS 失败且存储不变；  
2. status+revision 双条件：错 status / 错 revision 均为 false；  
3. 并发两个 `saveIfRevision(10)`：一成功一失败，最终 revision=11；  
4. Redis Lua JSON 损坏 **抛 Runtime**，不是 false；  
5. Engine：conflict 不调 Saga、不 `NodeExecutionResult::failed`、不 `onFail`、不把 status 写成 FAILED、不 plain save；  
6. SubWorkflow Runtime 不上抛为父 Node FAILED；  
7. Resume rollback CAS 成功 / 被别人抢写后 conflict 且无 plain save；  
8. 终态迁移：`RUNNING→COMPLETED/FAILED/CANCELLED`，`WAITING→RUNNING/CANCELLED`，`RUNNING→WAITING`。

回归：现有 Workflow / RedisRunStore / HITL / Saga / SubWorkflow / Coroutine 测试；`php -l`。

## 23. 实施顺序

```text
1. WorkflowRun.revision（public 属性）
2. WorkflowRunSnapshot 四件套
3. RunStoreInterface
4. InMemoryRunStore（先让单测可写）
5. RedisRunStore Lua 扩展 + 错误码
6. DbRunStore + schema + SQLite installer
7. 异常类
8. WorkflowEngine：创建仍 save；其余 CAS；resume rollback；cancellation
9. executeNodeOnce / handle* / start-resume catch
10. AbstractNode / SubWorkflowNode
11. Spy 实现与测试
```

## 24. 验收标准

- [ ] `WorkflowRun` 有 `revision`，新 Run 为 0。
- [ ] Snapshot 能持久化/恢复；旧数据缺字段视为 0。
- [ ] `version` 仍是定义版本。
- [ ] Redis / DB / InMemory 均有原子 `saveIfRevision` 与 `saveIfStatusAndRevision`。
- [ ] Redis 仍走 Lua + 现有 `evalScript` / WAITING TTL。
- [ ] JSON 损坏 / Redis 故障 ≠ CAS false。
- [ ] CAS 成功才 `N→N+1`；失败不改本地 revision。
- [ ] Engine 除创建外的 Runtime mutation / 状态迁移已离开无条件 `save()`。
- [ ] Resume rollback、`applyCancellationIfRequested` 无 stale `save()`。
- [ ] Conflict / Runtime：不 DAG、不 Node FAILED、不 `onFail`、不 Saga、不 `handleRunFailure` 写 FAILED。
- [ ] `saveIfStatus` 仍在，旧 HITL CAS 测可通过。
- [ ] `PauseNodeIdSpyRunStore` 等接口实现已补方法。
- [ ] 并发 CAS 与 HITL / Saga / SubWorkflow 回归通过。
- [ ] PHP 8.4 语法检查通过。

## 25. 最终语义

```text
                WorkflowRun.revision = N
                         │
          ┌──────────────┴──────────────┐
          │                             │
   Runtime mutation              status 迁移
   saveIfRevision(N)      saveIfStatusAndRevision(status, N)
          │                             │
          └──────────────┬──────────────┘
                         │
              Redis Lua / SQL WHERE / 内存 persisted*
                         │
                 success → N+1
                 mismatch → Conflict → Abort
                 storage error → WorkflowRuntimeException → Abort
```

要消除的错误链路（现码 `executeNodeOnce` 全捕获 + `handleRunFailure` 无条件 save 仍会走）：

```text
stale Run → save()/conflict 当业务失败 → Node FAILED → Saga → FAILED → save(stale)
```

本方案只修当前已存在的持久化与异常边界，不引入新的分布式协调，不扩大 Workflow 功能。
