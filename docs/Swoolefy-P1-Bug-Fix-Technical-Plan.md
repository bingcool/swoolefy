# Swoolefy 当前版本 P1 Bug 修复技术方案

> 适用分支：`swoolefy-6.2-x`  
> 修复范围：当前版本已确认的 4 个 P1 明确 Bug  
> 原则：**最小修改、保持现有架构、不新增复杂机制、不新增功能**  
> 对照代码日期：2026-09-20（`GoWaitGroup` / `CronForkRunner` / `WorkflowEngine` / `SwoolefyException`）

## 1. 修复目标

| 优先级 | 模块 | 问题 | 结论 |
|---|---|---|---|
| **P1** | `GoWaitGroup` | `wait()` 结束后 `reset()` 把 `waitCompleted` 清回 `false`，late callback 保护失效 | **明确 Bug**（timeout / 正常完成 / failFast 三条结束路径都有） |
| **P1** | `CronForkRunner` | `pid_file` 未生成时，`gcExitProcess()` 用 `check_pid_not_exist_count < 10` 占槽，ticker 20s × 10 ≈ **200s**；`procOpen` 启动轮询最长 10s 也不看原始进程是否已死 | **明确 Bug** |
| **P1** | `WorkflowEngine::cancel()` / `applyCancellationIfRequested()` | `_runCompleteFired` 只覆盖 RUNNING 协作取消的一条分支；`fresh.status === CANCELLED` 无条件再 `fireRunComplete()` | **明确 Bug** |
| **P1** | `SwoolefyException::appException()` | 直接读 `$trace[0]['function']`，空 trace / 缺 key 时处理器自身再抛 | **明确 Bug** |

本轮只修复上述 4 项，不扩展到 Pool、Redis `listWaiting()`、OutboundUrlGuard、`CommandRunner`（同类 pid_file GC 逻辑，列为 P2）或其他问题。

## 2. 总体原则

- 不改变现有架构。
- 不新增公共 API、状态枚举或后台组件。
- 优先复用已有状态和清理流程。
- 修复边界状态，不借机重构。
- 每项 Bug 都增加针对性回归测试。
- **文件路径以现码为准**，不以想象中的目录为准。

---

# 3. P1-01 GoWaitGroup timeout / late callback

## 3.1 现码事实

文件：`src/Core/Coroutine/GoWaitGroup.php`

`done()` / `initResult()` 入口已有：

```php
if ($this->waitCompleted) {
    return;
}
```

但所有 wait 结束路径都是：

```text
waitCompleted = true
    ↓
reset()          // 内部 waitCompleted = false
    ↓
return / throw
```

涉及 3 处 timeout、2 处 failFast、2 处正常完成（`wait()` + `waitWithErrorChannel()`），共 **同一 `reset()`**。

因此 timeout 后 late `done()` 仍会改 `count / result / channel`。正常完成后 late callback 同样能污染。

## 3.2 原方案问题

原方案只拆 `reset()`、并写「下一轮 wait 开始时 `waitCompleted = false`」。对照现码有两处冲突：

1. **`wait()` / `add()` / `start()` 在 `waitCompleted === true` 时直接 throw**（`wait() called again` / `add after wait()`）。若 `reset()` 不再清该标志，同一实例默认**不能**再 wait，除非改入口语义。
2. 若在 `wait()` 开头把 `waitCompleted` 置回 `false`，与仍在飞的上一轮 late callback 竞态：callback 到达时标志已是 false，会污染第二轮。只靠布尔闩不够。

`batchParallelRunWait()` 每次 `new static()`，现有 misuse 文案也要求「create a new GoWaitGroup instance」。

## 3.3 修正后的修复方案

**本轮默认：实例一次性。** 不把「同一实例第二轮 wait」做成必须能力。

```text
resetRuntimeState()
    只清 count / result / waiting
    不动 waitCompleted
```

`reset()` 改为只调 `resetRuntimeState()`，或直接删掉对 `waitCompleted` 的赋值。

所有 wait 结束路径保持：

```php
$this->waitCompleted = true;
$this->resetRuntimeState();
// 再 return / throw
```

callback 入口不变：`waitCompleted` 则 return。

下一轮 wait：测试与业务用 **新实例**。不要在 `wait()` 开头把 `waitCompleted` 清掉。

### 3.3.1 明确不做

- 不新增公开状态机 / 新公共方法。
- 不为了复用引入 generation（除非后续产品明确要求同一实例多次 `wait()`；那时再用私有 `$waitEpoch`，本轮不做）。

## 3.4 测试

必须覆盖（协程用例，`PHPUintTest/Coroutine`）：

1. 正常完成，结果完整。
2. timeout 抛 `SystemException`。
3. timeout 后 late `done()` / `initResult()` 不改 `count()`、不 push channel。
4. timeout 后 **新实例** 可以正常 wait（替代「同一实例第二轮」）。
5. 正常完成后 late `done()` 同样被忽略。
6. `waitWithErrorChannel`（failFast）结束路径同样 sticky。

---

# 4. P1-02 CronForkRunner pid_file 幽灵运行槽位

## 4.1 现码事实（两段等待，不要混成一段）

文件：`src/Worker/Cron/CronForkRunner.php`（不是 `src/Cron/`）

### A. 启动轮询（`procOpen()`，约 L349–364）

`pid_file` 配置存在时，最多等 `CRON_MAX_WAIT_FORK_TIME`（默认 **10s**），每 100ms `is_file()`。  
**循环内不检查 `proc_open` 返回的原始 PID 是否已死。** 进程秒退、永不写 pid_file 时仍空转满 10s，然后仍把 meta 放进 `runProcessMetaPool`。

`exec()` 路径（约 L256–263）更狠：文件当时不存在就把 `pid` 写成 **0**，原始 PID 丢掉。

### B. GC 占槽（`gcExitProcess()`，约 L671–677）——200s 的真正来源

```php
if ($runProcessMetaItem->check_pid_not_exist_count < 10) {
    $itemList[] = $runProcessMetaItem; // 继续占槽
}
```

`registerTickOfCheckRunningProcess()` ticker = `$checkTickerTime` = **20s**。  
`20s × 10 = 200s`。此间 `isNextHandle()` 仍把该项算进 `concurrent`。

`pid_file` 已配置但文件不存在时，**不看原始进程是否存活**。`pid === 0` 时 `isNextHandle()` 的 `kill(pid,0)` 分支也帮不上。

## 4.2 修正后的修复方案

两处都改，只复用现有 `gcExitProcess` / `waitForProcessExitAndDrainPipes` / `proc_get_status`，不新增 watcher。

### A. `procOpen()` 等待 pid_file

```text
pid_file 已出现且 PID 合法 → 写入 dto，退出循环
否则
    原始 proc_open PID 仍存活？
      YES → sleep 100ms，继续等（直到 deadline）
      NO  → 立即跳出，不再空等到 10s
```

跳出后走现有 `callable` + `waitForProcessExitAndDrainPipes` + `finally` 关 pipe/`proc_close`。  
不要把「已死且无 pid_file」的项当成仍在跑的 fork。

原始 PID：`proc_get_status($proc_process)['pid']`（shell 包装进程）。`Swoole\Process::kill($pid, 0)` 与现有 GC 一致。

### B. `gcExitProcess()`

`pid_file` 非空且文件不存在：

```text
原始 dto->pid > 0 且 kill(pid,0) 失败
    → 立即丢弃该项（不累加 check_pid_not_exist_count）
dto->pid == 0 且文件仍不存在
    → 视为启动失败，立即丢弃（exec() 丢 PID 的洞）
仍存活
    → 保持现有「继续等 pid_file」行为，可保留最多 10 次，避免误杀慢启动
```

### C. `exec()`（最小连带）

文件尚未生成时 **不要把 pid 改成 0**。保留 `echo $$` / `echo $!` 拿到的 PID，以便 B 能判断存活。这不是新功能，是让 B 的存活检查有输入。

## 4.3 处理原则

`pid_file absent AND original process dead`：

1. 停止等待 pid_file。
2. 能拿到则 `proc_get_status` 取退出码。
3. drain stdout/stderr（已有 `waitForProcessExitAndDrainPipes`）。
4. 现有 `finally` cleanup。
5. **立即**从 `runProcessMetaPool` 去掉该项，后续 `isNextHandle()` 可立刻再 fork。
6. 启动失败仍走现有异常 / 执行结果，不改日志协议。

进程仍存活则不能提前失败。

## 4.4 测试

`PHPUintTest/Coroutine` 或可 mock 的 Unit：

1. 正常生成 pid_file，dto.pid 更新为文件中的 PID。
2. pid_file 延迟出现，原始进程仍存活 → 等到文件，不判失败。
3. 进程立即退出且 pid_file 永不生成 → 等待循环提前结束，**slot 立即释放**（断言 `gcExitProcess` / pool size，不要等 200s）。
4. `exec()` 未生成文件时 pool 中 pid 仍为拉起 PID，死后一次 GC 即释放。
5. 后续任务能立刻 `isNextHandle() === true`。

本轮 **不改** `src/Core/CommandRunner.php` 里几乎相同的 10 次计数（记为 P2）。

---

# 5. P1-03 Workflow cancel / cancellation recovery 重复 fireRunComplete

## 5.1 现码事实

| 位置 | 行为 |
|---|---|
| `PluginManager::fireRunComplete()` L97–101 | **无幂等**，逐个 hook |
| `cancel(WAITING)` L235–239 | persist `CANCELLED` 后 fire，**不写** `_runCompleteFired` |
| `cancel(RUNNING)` L244–248 | 先 `_cancelRequested` + `_runCompleteFired`，persist 仍为 RUNNING，再 fire |
| `applyCancellationIfRequested` L599–604 | `fresh.status === CANCELLED` → **无条件** `fireRunComplete($run)`，且不把 `$fresh->state` 合并进 `$run` |
| 同函数 L608–621 | `_cancelRequested` 分支会读 `_runCompleteFired`，已 fire 则跳过 |

因此：

```text
cancel(RUNNING)
  → fire 第 1 次（_runCompleteFired 已持久化）
executeFromNode 下一轮 applyCancellationIfRequested
  → 已写成 CANCELLED
  → 走 L599 分支
  → fire 第 2 次
```

`cancel(WAITING)` 从未写 `_runCompleteFired`，任何 recovery 读到 `CANCELLED` 都会再 fire。

`RateLimitPlugin` 已按 `runId` 做释放幂等，所以双 fire 不一定双减槽位，但仍会让其他 `run.complete` hook 跑两遍。本轮修的是 **引擎侧最多调用一次 fire**。

## 5.2 原方案问题

「只在 `fireRunComplete()` 内部看 `_runCompleteFired`」对 **同一 PHP 对象** 的二次调用有效。

现码 recovery 用的是 **执行中的 `$run`**，flag 在 **`$fresh`（store 重读）** 上。L599 分支不合并 state，`$run->state` 可能没有 `_runCompleteFired`，闸门失效。

WAITING 取消若不把 flag **持久化**，跨调用 / 下一轮 `find()` 仍会再 fire。

## 5.3 修正后的修复方案

三处一起改，仍只用已有 `_runCompleteFired`，不新增状态枚举。

### 1) `PluginManager::fireRunComplete()` 对象级闩

```php
if ($run->state->get('_runCompleteFired', false)) {
    return;
}
$run->state->set('_runCompleteFired', true);
// 再调 hooks
```

### 2) 所有会 fire 的 cancel 路径，persist 必须带上 flag

`cancel(WAITING)` 在 `persistTransition` **之前** `$run->state->set('_runCompleteFired', true)`，与 RUNNING 路径对齐。

### 3) `applyCancellationIfRequested()` 以 `$fresh` 为准

```text
fresh.status === CANCELLED
    ↓
若 fresh.state._runCompleteFired
    → 只对齐内存 status/revision，return，不再 fire
否则
    → 把 flag 写到 $run.state，再 fireRunComplete（走 1 的闩）
```

`_cancelRequested` 分支保持现有 `completeAlreadyFired` 判断即可。

`start()` / `resume()` / `handleRunFailure()` 的正常完成、失败路径会经过 1) 的闩，重复调用变为空操作，不改 COMPLETED/FAILED 语义。

## 5.4 状态与事件必须分离

```text
WorkflowRun.status     = 业务状态
_runCompleteFired      = completion side-effect 是否已执行（须能从 RunStore 读回）
```

## 5.5 测试

`PHPUintTest/Unit/Support/Workflow/`，hook 计数器：

1. WAITING cancel，只 fire 一次，且 persist 后 `find()` 能读到 `_runCompleteFired`。
2. RUNNING cancel + 随后 `applyCancellationIfRequested`（CANCELLED 分支），只 fire 一次。
3. 重复 recovery（多次 `applyCancellationIfRequested`），只 fire 一次。
4. 正常 COMPLETED / FAILED 仍 fire 一次，不被误跳过。
5. 不新增 `CANCELLED` 枚举值，终态不可 cancel 的现有 throw 不变。

---

# 6. P1-04 SwoolefyException::appException() 异常处理链 Bug

## 6.1 现码事实

文件：`src/Core/SwoolefyException.php`（不是 `src/Exception/`）

```php
$trace = $exception->getTrace();
if ('E' == $trace[0]['function']) {
    $error['file'] = $trace[0]['file'];
    $error['line'] = $trace[0]['line'];
} else {
    $error['file'] = $exception->getFile();
    $error['line'] = $exception->getLine();
}
```

PHP 8+ 空数组读 `[0]`、缺 `function` 会 Warning；若 `handleError` 转成 `ErrorException`，异常处理器自己再抛，原始异常被盖住。

`'E' == function` 是历史兼容（类 ThinkPHP `E()`）。Swoolefy 主路径几乎不会命中，缺 key 时更应走 `getFile()` / `getLine()`。

## 6.2 修复方案

不改变现有输出结构，只防御读取：

```php
$trace = $exception->getTrace();
$firstTrace = $trace[0] ?? [];

if (($firstTrace['function'] ?? '') === 'E') {
    $error['file'] = $firstTrace['file'] ?? $exception->getFile();
    $error['line'] = $firstTrace['line'] ?? $exception->getLine();
} else {
    $error['file'] = $exception->getFile();
    $error['line'] = $exception->getLine();
}
```

`function !== 'E'` 时行为与现在 else 分支完全一致。

## 6.3 不做

- 重写 Error Handler。
- 改 JSON schema / HTTP 状态 / 日志结构。
- 新增异常包装。
- 动 `response()`（已有独立单测）。

## 6.4 测试

扩 `PHPUintTest/Unit/Core/SwoolefyExceptionResponseTest.php` 或新建：

1. 正常 trace（`function !== 'E'`）→ file/line 来自异常对象。
2. 空 trace。
3. `trace[0] = []`。
4. 缺 function/class/type/file/line。
5. `function === 'E'` 且带 file/line 时仍用 trace 中的位置。
6. `appException()` 自身不抛；`shutHalt` 入参 message 格式不变。

可用匿名异常 / 反射构造，或对 `appException` 包一层 catch 断言无二次异常。

---

# 7. 修改文件范围（现码路径）

```text
src/Core/Coroutine/GoWaitGroup.php
src/Worker/Cron/CronForkRunner.php
src/Support/Workflow/Engine/WorkflowEngine.php
src/Support/Workflow/Plugin/PluginManager.php
src/Core/SwoolefyException.php

PHPUintTest/Coroutine/Core/          # GoWaitGroup
PHPUintTest/Unit/Worker/Cron/        # CronForkRunner
PHPUintTest/Unit/Support/Workflow/   # cancel / recovery
PHPUintTest/Unit/Core/               # SwoolefyException
```

不迁移目录，不改 `CommandRunner`、Pool、RunStore。

---

# 8. 验证策略

## 8.1 静态检查

```bash
php -l src/Core/Coroutine/GoWaitGroup.php
php -l src/Worker/Cron/CronForkRunner.php
php -l src/Support/Workflow/Engine/WorkflowEngine.php
php -l src/Support/Workflow/Plugin/PluginManager.php
php -l src/Core/SwoolefyException.php
```

有 Mago 时只 lint 上述文件，不扩大历史 warning。

## 8.2 单元测试矩阵

```text
GoWaitGroup
├── normal completion
├── timeout + late done 不改 count
├── success + late done 忽略
├── failFast 结束路径 sticky
└── 新实例下一轮 wait 正常

CronForkRunner
├── pid_file 正常
├── pid_file 延迟且进程存活
├── 进程已死且无 pid_file → 立即释放 slot
├── exec() pid 不被写成 0
└── isNextHandle 可立刻再 fork

Workflow
├── WAITING cancel 一次 fire + flag 持久化
├── RUNNING cancel + CANCELLED recovery 一次 fire
├── 重复 recovery 一次 fire
└── 正常 complete / fail 仍一次 fire

SwoolefyException
├── 正常 trace
├── 空 / 残缺 trace 不二次抛
└── message/file/line 格式不变
```

---

# 9. 回归重点

### GoWaitGroup

修复后 `waitCompleted` 必须在该实例生命周期内保持终态。  
**不要**在 `wait()` 开头清回 false。下一轮用新实例。

### CronForkRunner

```text
alive → 继续等 pid_file（可至 10s / 最多 10 次 GC）
dead  → 立即启动失败并释放 slot
```

慢启动（脚本晚写 pid_file）不能误杀。区分 10s 启动轮询与 200s GC 占槽，两处都要测。

### Workflow

不新增 `RunStatus`。只保证 `run.complete` hook 每个 Run 最多真正执行一轮。flag 必须能从 RunStore `find()` 回来。

### Error Handler

正常异常的 message / file / line / `shutHalt` 行为不变。`response()` 不在本轮改动范围。

---

# 10. 本轮明确不做

```text
❌ 新增 GoWaitGroup 公开状态机 / waitEpoch（除非以后要复用实例）
❌ 新增 CronForkRunner 独立 watcher 进程
❌ 改 CommandRunner 的同类 GC（P2）
❌ 新增 Workflow completion 状态枚举或 event 表
❌ 新增分布式锁
❌ 重构 Error Handler / 改 HTTP JSON
❌ 修改 Pool / Redis Store / HTTP Gateway
❌ 扩展 Cron 功能
```

> **只修 Bug，不借机重构。**

---

# 11. 最终验收标准

```text
P1-01 GoWaitGroup
────────────────────────
wait 结束后 waitCompleted 保持 true
late callback 不再改 count/result/channel
新实例可以开始下一轮 wait


P1-02 CronForkRunner
────────────────────────
pid_file 未生成 + 原始进程已退出
        ↓
立即从 runProcessMetaPool 移除
不再依赖 20s×10 的 GC 计数


P1-03 Workflow
────────────────────────
cancel + applyCancellationIfRequested(CANCELLED)
        ↓
fireRunComplete 的 hook 体最多执行一次
_runCompleteFired 可从 store 读回


P1-04 Error Handle
────────────────────────
trace 缺失 / 为空 / 缺 function
        ↓
appException() 不再自身抛错
```

四项完成后：P1 清零，每项带回归测试，改动面限于第 7 节文件。

---

# 12. 建议提交拆分

```text
fix: 修复 GoWaitGroup wait 结束后 late callback 仍改运行态

fix: 修复 CronForkRunner pid_file 未生成时幽灵 slot 占用

fix: 修复 Workflow cancel/recovery 重复 fireRunComplete

fix: 修复 SwoolefyException::appException 空 trace 二次抛错
```

每项独立 review、独立回滚。
