# MainManager 拆分方案

> 范围：`src/Worker/MainManager.php`（约 2230 行）及其 Trait。  
> 目标：把「启动编排、信号处理、动态进程、CLI 管道、confctl」从同一个类里拆开，降低分组 / 重启改动互相踩踏的风险。  
> 原则：先抽边界清晰的模块，**不改对外行为**；`MainManager` 暂时保留为组合根与兼容门面。

## 一、现状

`MainManager` 是 Daemon / Cron WorkerService 的管理进程核心。`AbstractMainProcess` 加载配置后调用 `loadConf()` + `start()`；子进程通过 `AbstractBaseWorker` 向它发动态扩缩容 / reboot 指令；CLI 与 `CtlApi` 走 FIFO 管道和静态 `includeWorkerConf()`。

已有局部拆分，但不够：

| 已抽出 | 仍挤在 MainManager |
|--------|-------------------|
| `ConfCtlStore`：confctl.json 原子读写 | 配置 include、分组解析、与 confctl 合并 |
| `Helper`：CLI `--group` / 重启参数 | PID 目录身份只在 `makeServerName()` |
| `MainProcessCommandTrait`：start/stop/restart 单进程 | FIFO 安装与 action 分发仍写在 `installCliPipe()` |
| `SystemTrait`：错误处理、内部日志 | 信号、关机、状态上报 |

### 1.1 职责混杂（按代码块）

```
loadConf / addProcess / initStart / start     启动编排
install*Signal / shutdownMainManager          信号与关机
forkNewProcess / create|destroyDynamic        动态进程 + SIGCHLD 回收
swooleEventAdd / writeByProcessName           进程间管道 IPC
installCliPipe / parseLoadConf                CLI FIFO + 控制面
loadWorkerConf / resolveGroupedWorkerConf     配置与分组
installReportStatus / getProcessStatus        状态上报
```

这些块共享同一套私有表：`$processLists`、`$processWorkers`、`$stoppingDynamicProcesses`、`$isExit`、`$cliPipeFd`、`$reportStatusTimerId`。改分组或重启时，经常要同时动配置加载、PID 文件、FIFO、confctl 和关机顺序，因此容易互相踩。

### 1.2 对外依赖（拆分时必须兼容）

| 调用方 | 用法 |
|--------|------|
| `AbstractMainProcess` | `getInstance()`、`loadConf()`、`loadWorkerConf()` |
| `AbstractBaseWorker` | `getInstance()`、常量 `MASTER_WORKER_NAME` / `CREATE_DYNAMIC_PROCESS_WORKER` 等、`writeByProcessName` 对端 |
| `CtlApi` | `includeWorkerConf()`（分组过滤后的进程列表） |
| `MainDaemonProcess` / `MainCronProcess` | `getInstance()`、`start()`、`onReportStatus` |
| 单测 | `ReflectionMethod` 打 `forkNewProcess`、`waitWorkersExitOrKill`、`resolveShutdownWaitSeconds`、`resolveGroupedWorkerConf` |

公共方法与常量在过渡期**全部保留在 `MainManager` 上**，内部改为委托。

---

## 二、拆分原则

1. **组合优于再堆 Trait**。继续往 `MainManager` 加 Trait 只会把 `$this->processWorkers` 变成隐性全局状态，分组/重启仍会改到同一份私有字段。
2. **先抽出无 EventLoop 的纯逻辑，再动运行时**。配置加载、分组解析几乎无 Swoole 依赖，可先独立并被单测钉住。
3. **可变状态只放一处**。进程表不复制多份；生命周期 / 管道 / 关机都依赖同一个 Registry。
4. **不引入消息总线或新进程模型**。拆的是类边界，不是运行时拓扑。Master 仍是单进程 EventLoop。
5. **一次只迁一个子系统，每阶段可独立合并**。禁止「一次 PR 拆完 2200 行」。

### 2.1 推荐结构（目标态）

```
src/Worker/
  MainManager.php                 # 组合根：start() 装配，公共 API 委托
  Process/
    ProcessRegistry.php           # 进程表：lists / workers / pid / stopping
    ProcessSupervisor.php         # fork / reboot / 动态扩缩容 / SIGCHLD
    ProcessIpc.php                # Event::add(pipe) 与 write/broadcast
  Runtime/
    SignalShutdown.php            # SIGTERM/INT/HUP、关机等待、清 timer
    StatusReporter.php            # tick 写 WORKER_STATUS_FILE
    CliPipeServer.php             # FIFO 监听与 CLI action 分发
  Config/
    WorkerConfLoader.php          # include / 分组 / 去重 / 与 ConfCtlStore 合并
```

`MainManager` 最终只负责：

- 构造与单例
- `start()` 里按固定顺序调用上述组件
- 把旧 public API 转给组件（兼容门面）

---

## 三、模块边界

### 3.1 `WorkerConfLoader`（最先拆，风险最低）

**迁入：**

- `loadWorkerConf()` / `includeWorkerConf()` / `defaultLoadWorkerConf()`
- `isGroupedWorkerConf()` / `resolveGroupedWorkerConf()` / `normalizeGroupProcessItems()`
- `findDuplicateProcessName()`
- 静态 `$confPath`

**依赖：** `Helper`（`--group`）、`ConfCtlStore`、`WORKER_CONF_FILE`。

**不依赖：** 进程表、信号、FIFO。

**门面：** `MainManager::loadWorkerConf()` 等全部改为 `WorkerConfLoader::...` 的静态转发，避免 `CtlApi`、`AbstractMainProcess` 立刻改 import。

**验收：** 现有 `WorkerGroupIdentityTest`、`ConfCtlStoreTest` 仍绿；补 Loader 单测（扁平 conf / 分组 / 重复 process_name / confctl running=0 过滤）。

---

### 3.2 `ProcessRegistry`（后续所有运行时模块的底座）

**持有：**

- `$processLists`、`$processWorkers`
- `$processPidMap`、`$processStatusList`
- `$stoppingDynamicProcesses`
- 查询：`getByName` / `getByPid` / `has` / `countAlive`

**规则：**

- 只有 Supervisor 可以增删 worker 实例。
- CLI / CtlApi / StatusReporter **只读 Registry**，禁止直接 `unset($this->processWorkers)`。
- key 仍用 `md5($processName)`，避免这一阶段行为变化。

**验收：** `getProcessByName` / `getProcessByPid` 行为与现在一致；动态进程单测改为走 Registry 或仍通过 MainManager 门面。

---

### 3.3 `ProcessSupervisor`（动态进程与回收）

**迁入：**

- `addProcess()` / `loadConf()` / `initStart()` / `forkNewProcess()`
- `createDynamicProcess()` / `destroyDynamicProcess()` / `storageDynamicProcessNum()`
- `rebootWorker()` / `rebootOrExitHandle()`
- `installSigchldSignal()` 中与回收相关的部分

**依赖：** `ProcessRegistry`、`ProcessIpc`（fork 后 `Event::add`）。

**约束（保持现有正确行为）：**

- 动态进程 `process_type` 与计数时机已在生产审计中修过，迁代码时**原样搬**，不顺手改语义。
- `isMasterExiting()` 为 true 时禁止 create dynamic。
- fork 失败必须从 Registry 回滚，避免表里有对象、进程没起来。

---

### 3.4 `ProcessIpc`

**迁入：**

- `swooleEventAdd()`
- `writeByProcessName()` / `writeByMasterProxy()` / `broadcastProcessWorker()`
- 管道消息里对 `CREATE_DYNAMIC_*` / `DESTROY_DYNAMIC_*` / `REBOOT_PROCESS_WORKER` 的分发（调用 Supervisor，不自己改表）

**约束：** unserialize 继续 `allowed_classes` 白名单（`MessageDtoWorker`）。

---

### 3.5 `CliPipeServer`

**迁入：**

- `installCliPipe()` / `enableCliPipe()` / `getCliToWorkerPipeFile()`
- FIFO 上的 `WORKER_CLI_STATUS|STOP|RESTART|SEND_MSG`
- 与 `MainProcessCommandTrait` 合并：Trait 变成 `CliPipeServer` 的私有方法，或 Server 依赖一个 `ProcessCommandHandler`

**与分组/重启的关系（这是拆分的直接收益）：**

- STOP → 只调 `SignalShutdown::shutdown('cli-pipe')`，不再在 MainManager 里内联 80 行。
- RESTART → 只调已带回 `--group/--only` 的 `restartServerCommand()`。
- START 指定进程 → 经 `WorkerConfLoader` 取**当前实例分组**配置，禁止跨组拉起。

`CtlApi` 与 CLI 共用同一套 command handler，避免 HTTP 控制面和 FIFO 两套语义。

---

### 3.6 `SignalShutdown`

**迁入：**

- `installMasterStopSignal()` / `installMasterReloadSignal()` / `installSignal()` / `addSignal()`
- `shutdownMainManager()` / `waitWorkersExitOrKill()` / `resolveShutdownWaitSeconds()`
- `clearReportStatusTimer()`（或通知 StatusReporter 停止）
- `$isExit` 的唯一写入点

**关机顺序（不得打乱，现有 macOS FIFO 残留问题靠此顺序）：**

1. 置 `$isExit`，拒绝重入  
2. 通知全部业务子进程退出  
3. `waitWorkersExitOrKill`（预算 = wait_time + maxWaitTimeOfExit + margin）  
4. 清 status timer、关 FIFO、删属于本实例的 PID 文件  
5. 退出 EventLoop  

**验收：** `GracefulShutdownWaitBudgetTest` 仍绿。

---

### 3.7 `StatusReporter`

**迁入：**

- `installReportStatus()` / `getProcessStatus()` / `saveStatusToFile()`
- `statusInfoFormat()` / `getOptionParams()` / SwooleTable / Sysvmsg 摘要

只读 Registry；timer 回调禁止开协程（与现注释一致，避免 master 异步 IO 导致后续 fork 失败）。

---

## 四、MainManager 保留什么

过渡期 `MainManager` 类似：

```text
start():
  installErrorHandler
  SignalShutdown.install()
  StatusReporter.install()
  ProcessSupervisor.initStart()
  CliPipeServer.install()
  ProcessIpc.bindAll()
  onStart
```

对外仍提供：

- `getInstance()`（Singleton 不拆）
- `loadConf()` / `start()` / `addProcess()`
- `createDynamicProcess()` / `destroyDynamicProcess()`
- `loadWorkerConf()` / `includeWorkerConf()` / `resolveGroupedWorkerConf()`
- 常量 `MASTER_WORKER_NAME`、`CREATE_DYNAMIC_PROCESS_WORKER` 等

应用侧 `MainDaemonProcess` **不需要改**。

---

## 五、实施阶段

| 阶段 | 内容 | 预估触文件 | 风险 |
|------|------|------------|------|
| **P0** | 抽出 `WorkerConfLoader`，MainManager 静态方法转发 | `MainManager`、新 Loader、CtlApi 可暂不改 | 低 |
| **P1** | 抽出 `ProcessRegistry`，MainManager 字段改为持有 Registry | MainManager、动态进程/关机单测 | 中 |
| **P2** | `CliPipeServer` + 合并 Trait 命令 | MainManager、Trait、CtlApi | 中（FIFO / 分组重启） |
| **P3** | `ProcessSupervisor` + `ProcessIpc` | MainManager、AbstractBaseWorker 常量仍指向门面 | 中高 |
| **P4** | `SignalShutdown` + `StatusReporter` | 关机单测、status 文件 | 中 |

每阶段独立提交、独立回归。P0 可立刻做；P1 是后续阶段的前置。不要从 P3 起跳。

### 5.1 明确不做

- 不为拆类引入 EventBus、中间件管道或新的自定义进程。
- 不把 Registry 做成 Redis/Swoole Table 分布式状态（仍是单 Master 内存表）。
- 不在拆分 PR 里改动态进程计数语义、关机等待公式、或 `--group` 规则。
- 不强制应用改 `MainManager::getInstance()` 调用点。

---

## 六、测试与回归

拆分过程以**现有单测搬家或改反射目标**为主，必要时补：

| 模块 | 用例 |
|------|------|
| Loader | 扁平 conf；`--group` 单组/多组/缺省全组；重复 `process_name` 抛错；confctl `running=0` 不启动 |
| Registry | add/get/remove；fork 失败回滚 |
| CliPipe | STOP 走统一 shutdown；RESTART 命令行含 `--group`；START 拒绝非本实例进程 |
| Supervisor | 与 `DynamicProcessLifecycleTest` 等价 |
| Shutdown | 与 `GracefulShutdownWaitBudgetTest` 等价 |

手工回归：

```bash
php daemon.php start App
php daemon.php start App --group=group_1
php daemon.php restart App --group=group_1 --force=1
php daemon.php stop App --group=group_1
```

确认两实例 PID 目录互不覆盖，`confctl.json` 互不清洗。

---

## 七、建议落地顺序（结论）

1. **先做 P0 `WorkerConfLoader`**：配置与分组已经独立演进（`--group`、PID 后缀、重启回传），继续放在 2200 行类里最容易和 confctl/FIFO 缠在一起。  
2. **再做 P1 Registry**：没有统一进程表，后面拆 Supervisor / Pipe / Shutdown 仍会传来传去 `$this`。  
3. **FIFO（P2）优先于动态进程（P3）**：最近分组/重启改动都走控制面，边界收益最大。  
4. 信号与 status 最后搬，逻辑已相对稳定。

完成 P0–P2 后，`MainManager.php` 预计可降到约 **600–800 行**（编排 + 门面）；P3–P4 后再降到 **300 行以内** 的组合根。

---

## 八、落地状态（已实施）

P0–P4 已按本文落地，`MainManager` 作为组合根 + 兼容门面：

| 模块 | 路径 |
|------|------|
| WorkerConfLoader | `src/Worker/Config/WorkerConfLoader.php` |
| ProcessRegistry | `src/Worker/Process/ProcessRegistry.php` |
| ProcessSupervisor | `src/Worker/Process/ProcessSupervisor.php` |
| ProcessIpc | `src/Worker/Process/ProcessIpc.php` |
| ProcessCommandHandler | `src/Worker/Runtime/ProcessCommandHandler.php` |
| CliPipeServer | `src/Worker/Runtime/CliPipeServer.php` |
| SignalShutdown | `src/Worker/Runtime/SignalShutdown.php` |
| StatusReporter | `src/Worker/Runtime/StatusReporter.php` |

对外 `MainManager::getInstance()` / `loadConf()` / `start()` / `loadWorkerConf()` / 动态进程 API **保持兼容**。应用侧 `MainDaemonProcess` 无需修改。

`MainProcessCommandTrait` 仅保留转发。`CtlApi` 仍走 `MainManager::includeWorkerConf()` 门面。
