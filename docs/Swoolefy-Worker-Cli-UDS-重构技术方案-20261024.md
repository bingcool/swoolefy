# Swoolefy Worker ↔ CLI 控制通道改造为 Unix Domain Socket —— 技术方案

- 日期：2026-10-24
- 分支：`swoolefy-6.2-x`
- 关联：全框架 Bug 审计 P1-04（`docs/Swoolefy-全框架Bug审计-20261024.md`）
- 范围：**只替换 CLI ↔ Worker Master 的控制通道传输**。Master ↔ 业务子进程的 `Swoole\Process` pipe IPC（[ProcessIpc.php:56](../src/Worker/Process/ProcessIpc.php#L56)）**不动**，`status.log` / `confctl.json` 文件通道**不动**。

---

## 1. 为什么要换，换掉的不止一个 bug

现状用两条有名管道（FIFO）做双向：`cli.pipe`（CLI→Worker，Worker 侧常驻单读端）与 `ctl.pipe`（Worker→CLI，CLI 每次现建现删）。已确认的缺陷：

| # | 缺陷 | 证据 | 后果 |
| --- | --- | --- | --- |
| 1 | macOS 上 `flock()` 对 FIFO 失败，而 `fwrite` 被关在 `if (flock(...))` 分支里，分支后仍 `return true` | [ServerLifecycleManager.php:242-246](../src/Cmd/Application/ServerLifecycleManager.php#L242) | 消息**没写出去但报告成功**；`stop` 静默失效（还有 SIGTERM 兜底），`send` 卡到超时且无输出 |
| 2 | `SendCmd` / `StatusCmd` 不检查发送返回值 | [SendCmd.php:103-108](../src/Cmd/Command/SendCmd.php#L103)、[StatusCmd.php:131](../src/Cmd/Command/StatusCmd.php#L131) | 失败不可见，只能等 `Timer::after` 超时后 `Event::exit()` |
| 3 | 读端 `fread($fd, 8192)` + 一次 `unserialize`，没有分帧也没有跨次读取缓冲 | [CliPipeServer.php:71](../src/Worker/Runtime/CliPipeServer.php#L71)、[FifoPipeClient.php:74](../src/Cmd/Infrastructure/FifoPipeClient.php#L74) | 报文 > 8192 字节（长 `--msg`）或一次事件里来多条 → `unserialize` 失败，指令被丢弃 |
| 4 | 多条消息并发写同一条 FIFO 靠 `flock` 串行化，而 macOS 上 `flock` 不可用 | 同上 #1；框架内已有正确结论：[CtlApi.php:268-269](../src/Worker/CtlApi.php#L268) | 只在报文 ≤ `PIPE_BUF` 时才偶然安全，无显式约束 |
| 5 | 响应通道靠「先建 FIFO 再发消息」的三步硬时序 + `sleep(1)` 等注册 | [FifoPipeClient.php:13-27](../src/Cmd/Infrastructure/FifoPipeClient.php#L13)、[StatusCmd.php:128-131](../src/Cmd/Command/StatusCmd.php#L128) | 时序写错就静默丢响应；`sleep(1)` 是拍的，不是保证 |
| 6 | FIFO 与 `0777` 的运行时目录 | [CliPipeServer.php:63](../src/Worker/Runtime/CliPipeServer.php#L63)、[BaseCmd.php:449-450](../src/Cmd/BaseCmd.php#L449) | 同机任意用户可写 `cli.pipe` 注入 `WORKER_CLI_STOP`（远程前置条件低的 DoS），也可读 `ctl.pipe` 拿进程清单 |
| 7 | FIFO 残留会把 Master 拖住，关机顺序被写死成注释里的警告 | [CliPipeServer.php:28](../src/Worker/Runtime/CliPipeServer.php#L28)、[SignalShutdown.php:155-160](../src/Worker/Runtime/SignalShutdown.php#L155) | 「只停子进程」在 macOS 上让 Master 假死，是这套传输模型带来的约束 |

UDS + 显式长度分帧一次性解决 1–6；#7 的关机顺序约束保留（改为 socket 后仍需 `Event::del` + `fclose` + `unlink`）。

---

## 2. 目标设计

### 2.1 拓扑

```
CLI 进程 (swoolefy status|stop|restart|send / CtlApi HTTP)              Worker Master (MainManager)
      │                                                                    │
      │  stream_socket_client("unix:///tmp/workerfy/log/<svc>/ctl.sock")   │  stream_socket_server + Event::add
      ├────────── connect ──────────────────────────────────────────────►  │  accept（非阻塞，回调内）
      ├────────── [4B 长度][JSON 请求帧] ───────────────────────────────►  │  读缓冲状态机 → dispatch
      ◄───────── [4B 长度][文本/JSON 响应帧] ──────────────────────────────  │  同连接回写
      └────────── close ────────────────────────────────────────────────►  │  立即关闭该连接
```

要点：

- **一条连接跑完一次请求/响应**，`WORKER_TO_CLI_PIPE` 整条通道删除，#5 的三步时序约束与 `sleep(1)` 一起消失。
- **连接生命周期 = 一个请求**，不做 keep-alive；`dispatch` 同步跑完就回帧并关闭。
- **请求载荷从 PHP `serialize()` 换成 JSON**：不再有反序列化 gadget 面（现在靠 `allowed_classes` 白名单兜着，[CliPipeServer.php:72](../src/Worker/Runtime/CliPipeServer.php#L72)），也让 CtlApi / 未来 HTTP 控制面能直接构造。
- **权限收紧**：socket `0700`，运行时目录由 `0777` 改 `0750`（见 §5 的兼容性影响）。

### 2.2 帧格式

与 Go sidecar 的帧约定同族，便于心智统一（4 字节大端长度 + 负载）：

```text
请求帧：uint32 BE 长度 N (1 ≤ N ≤ 1 MiB) + N 字节 UTF-8 JSON
响应帧：uint32 BE 长度 N (1 ≤ N ≤ 1 MiB) + N 字节 UTF-8 JSON
```

请求 JSON：

```json
{
  "v": 1,
  "action": "status",              // status | stop | restart | send_msg
  "target": "AppDaemon",           // 原 targetHandler；status/stop/restart 传空串
  "params": {                      // 结构化字段，不再嵌 JSON 字符串
    "op": "start",                 // send_msg 用：start | stop | restart | 透传指令名
    "msg": ""                      // 透传指令的原始内容
  },
  "nonce": "cli-1730000000-42"     // 仅用于日志串联，不参与业务判断
}
```

`params` 取代原来的「`message` 里再放一段 JSON 字符串」。这条嵌套现在有三处在跑：写侧 [SendCmd.php:107](../src/Cmd/Command/SendCmd.php#L107)、[CtlApi.php:276](../src/Worker/CtlApi.php#L276)，读侧 [CliPipeServer.php:147](../src/Worker/Runtime/CliPipeServer.php#L147) 的 `json_decode`。本次重构的目标之一就是去掉隐式序列化面，协议里再留一层字符串 JSON 与之相悖。

响应统一一个信封，`status` 不再走裸文本帧（客户端只保留一套解析）：

```json
{ "ok": true, "message": "进程【AppDaemon】已开始启动，请留意！", "data": "", "nonce": "cli-1730000000-42" }
```

- `message`：给人看的一行文案，CLI 直接打印。
- `data`：`status` 放渲染好的表格文本（渲染仍归 `StatusReporter`，协议层不掺和），其它动作回空串。
- 若坚持 `status` 用裸文本帧，只改这一处即可，其余不受影响（见 §8 决策 1）。

`action` 与现有常量 `WORKER_CLI_STATUS/STOP/RESTART/SEND_MSG` 一一对应，映射表放在 `src/Constants.php` 旁边新增的 `WorkerControlAction` 常量类里，避免字符串散落。

### 2.3 硬约束：Master 侧不得使用协程

这是本方案最重要的一条，来自框架现有注释：

- [StatusReporter.php:29-30](../src/Worker/Runtime/StatusReporter.php#L29)：Timer 回调禁止开协程，否则 Master 后续 `fork` 报 `unable to create Swoole\Process with async-io threads`。
- [MainManager.php:337-338](../src/Worker/MainManager.php#L337)：先装 timer 再 fork，同一个理由。

所以 Master 侧的实现必须是**纯事件回调**：`stream_socket_server()` + `stream_set_blocking(false)` + `\Swoole\Event::add($server, ...)`，在回调里用 `stream_socket_accept($server, 0)` 取连接（EAGAIN 直接返回 false，不阻塞）。**禁止** `Swoole\Coroutine\Socket`、`Coroutine::create`、`Coroutine::sleep`。

CLI 侧没有 fork，可以随便用协程；但**同步阻塞实现更简单也更可调试**，所以选它：`stream_socket_client(..., $errno, $errstr, $connectTimeout)` + `stream_set_timeout` + 分帧读循环，整体一个墙钟 deadline。这样 `Swoole\Event::wait()` / `Timer::after` / `Event::exit()` 这三个 CLI 侧的循环原语全部不再需要。

落地时要盯一个平台细节：`stream_socket_accept($server, 0)` 配合 `stream_set_blocking(false)` 在无挂起连接时应立刻返回 `false`，但它历史上受 `default_socket_timeout` 影响过。若实测出现等待，退路是换 ext-sockets 的 `socket_set_nonblock()` + `socket_accept()`（`sockets`、`posix` 两个扩展在宿主机 PHP 8.4.14 与容器 PHP 8.5.8 上均已加载，实测确认），语义更直白且不依赖流包装器。§6 的并发场景就是用来暴露这个差异的。

### 2.4 并发模型

FIFO 时代只有单读端，天然串行。UDS 会同时出现多个连接，而 `dispatch` 的副作用是 fork/停子进程/改 `lists`（[CliPipeServer.php:143-198](../src/Worker/Runtime/CliPipeServer.php#L143)），并发跑会互相踩。

Master 的事件循环是单线程的，`dispatch` 又在读回调里同步跑完（现状即如此，[:69-79](../src/Worker/Runtime/CliPipeServer.php#L69)），所以**任何时刻只可能有一个请求在执行**，不需要应用层队列——内核的 listen backlog 就是队列。原方案的「最多同时处理 1 个 + 队列上限 16 + 排队」删掉，只留三条：

- **活跃连接上限**（默认 32）：`accept` 时若已有 32 条在飞，直接回 `ok:false, message:"busy"` 一帧并关闭；更晚的请求由 backlog 排队，靠客户端 connect 超时兜底。
- **完整帧到达立即 dispatch**：每连接一个 `ConnState`（读缓冲 + deadline），凑齐一帧才 dispatch，避免 #3 的半包问题。
- **每连接 deadline**（默认 30 秒）：只用来清理「连上不发数据」的连接。`Timer::after` 不建协程，符合 §2.3。

少一层队列状态机能少一半分支，且 §6 的并发场景表现完全一致。

---

## 3. 需要改造的地方（逐项，含现有行为要保留什么）

### 3.1 Worker 侧

| 文件 | 现状 | 改成 | 备注 |
| --- | --- | --- | --- |
| [src/Worker/Runtime/CliPipeServer.php](../src/Worker/Runtime/CliPipeServer.php) | `install()` 用 `posix_mkfifo` + `fopen('w+')` + `Event::add` + 一次 `fread 8192` | 重命名为 `CliControlServer`（同目录），`install()` 改为 `stream_socket_server('unix://'.ctlSocketPath())`，`chmod 0700`，`Event::add` 监听 accept；新增活跃连接上限与分帧读状态机；`close()` 变成「`Event::del` 监听 fd → 关闭所有存活连接 → `fclose` → `unlink` socket 文件」 | `dispatch(PipeMsgDtoWorker)` 的**业务语义完全保留**：`STOP` 仍必须走 `shutdownMainManager('cli-pipe')`（[:93-96](../src/Worker/Runtime/CliPipeServer.php#L93)）、`SEND_MSG` 的分组隔离与「未知进程名拒绝 START」（[:150-164](../src/Worker/Runtime/CliPipeServer.php#L150)）。建议把 dispatch 入参改成值对象 `ControlRequest` + `ReplySink`，单测仍可直接调 dispatch，不建真 socket。**`install()` 的三条前置规则必须写死**（见 §3.6） |
| [src/Worker/Runtime/ProcessCommandHandler.php:53-58](../src/Worker/Runtime/ProcessCommandHandler.php#L53) | `responseMsgByPipe()` 每次 `fopen(WORKER_TO_CLI_PIPE)` 裸写、不判返回值 | 改为写当前 `ReplySink`；无当前连接时（例如 CtlApi 的 HTTP 路径触发）**记 debug 日志后 no-op，不抛异常** | 9 个调用点（[CliPipeServer.php:155-187](../src/Worker/Runtime/CliPipeServer.php#L155)）签名不动，用「当前会话」解析，见 §3.5 |
| [src/Worker/Runtime/StatusReporter.php:232-290](../src/Worker/Runtime/StatusReporter.php#L232) | `masterStatusToCliFifoPipe(string $ctlPipeFile)`，6 次 `fwrite` 到 FIFO，`@fclose` | `masterStatusToCli(ReplySink $sink)`：先在内存拼完整文本，再单帧写出（长度上限由 §2.2 的 1 MiB 兜住）；`targetHandler` 不再携带回包路径 | `enable_cli_pipe` 状态字段保留语义（[:135](../src/Worker/Runtime/StatusReporter.php#L135)、[:151](../src/Worker/Runtime/StatusReporter.php#L151)、[:313](../src/Worker/Runtime/StatusReporter.php#L313)），改判 `CliControlServer::isListening()` |
| [src/Worker/MainManager.php:342-370](../src/Worker/MainManager.php#L342) | 启动第 5 步 `$this->getCliPipeServer()->install()` | 位置与顺序**不变**（必须在 `setRunning()` 之后、`installSigchldSignal()` 之前，理由见 [:337-338](../src/Worker/MainManager.php#L337)） | 只换被调用的类名/方法名 |
| [src/Worker/MainManager.php:694-721](../src/Worker/MainManager.php#L694) | `enableCliPipe()` / `isCliPipeEnabled()` / `getCliToWorkerPipeFile()` 返回 `CLI_TO_WORKER_PIPE` | 改名 `enableCliControl()` / `isCliControlEnabled()`，路径访问器换成 `getCliControlSocketFile()`，值由 `WORKER_PID_FILE_ROOT . '/ctl.sock'` 推导 | 见 §3.4 关于常量的取舍 |
| [src/Worker/Runtime/SignalShutdown.php:126-150](../src/Worker/Runtime/SignalShutdown.php#L126)、[:155-160](../src/Worker/Runtime/SignalShutdown.php#L155) | 关机第 4 步调 `CliPipeServer::close()` | 调用点不变，注释里「关 FIFO」改「关控制面 socket」，并补一条：**unlink 前必须比对 pid 文件里的 pid 是本实例**，防止误删新启动实例正在监听的 sock（与 sidecar「只删本进程登记的 sock」纪律一致，见 `AGENTS.md`） | 关机 5 步顺序一个字不能动 |
| [src/Worker/Traits/MainProcessCommandTrait.php:40-42](../src/Worker/Traits/MainProcessCommandTrait.php#L40) | 转发 `responseMsgByPipe` | 转发保持，作为应用侧扩展点语义不变 | 这是用户可继承的入口，不能改成必须传参 |
| [src/Worker/CtlApi.php:264-291](../src/Worker/CtlApi.php#L264) | HTTP 控制面自己 `fopen(CLI_TO_WORKER_PIPE,'w+')` + 裸 `serialize` + 长度校验 | 复用 §4 的 `ControlClient`（同一套帧与超时），异常文案照旧 `WorkerException` | 注意这是在 HTTP/协程上下文里调用，而 `ControlClient` 是同步阻塞 IO；Swoole 协程钩子会让 `stream_socket_client` 走 hook，超时参数继续生效，可接受。若想彻底不混，就在 CtlApi 里保留直连但改用同一个编解码器 |

### 3.2 CLI 侧

| 文件 | 现状 | 改成 |
| --- | --- | --- |
| [src/Cmd/Infrastructure/FifoPipeClient.php](../src/Cmd/Infrastructure/FifoPipeClient.php) | 三步时序 + `posix_mkfifo` + `Event::add/wait/exit` + `Timer::after` | 删除，替换为 `ControlClient`：`request(string $socketPath, ControlRequest $req, float $timeoutSeconds): ControlResponse`，内部 connect → 写帧 → 读帧 → close；失败一律抛/返回带原因的失败结果 |
| [src/Cmd/Application/ServerLifecycleManager.php:208-250](../src/Cmd/Application/ServerLifecycleManager.php#L208) | `sendWorkerPipeMessage()` 返回恒真 | 走 `ControlClient`，返回值如实反映「已写出去且 Master 已接收」；`stopWorkerService()` 的 SIGTERM 兜底（[:140-144](../src/Cmd/Application/ServerLifecycleManager.php#L140)）保留，并在兜底触发时打印一条明确提示 |
| [src/Cmd/Command/SendCmd.php:89-115](../src/Cmd/Command/SendCmd.php#L89) | 忽略返回值 + `waitForResponse()` | `if (!$res->ok) { fmtPrintError(...); return Command::FAILURE; }` 后再打印响应文本。没有「先发后建管道」的顺序问题了，注释整段替换 |
| [src/Cmd/Command/StatusCmd.php:99-139](../src/Cmd/Command/StatusCmd.php#L99) | `filetype(...) === 'fifo'` 校验 + `fopen('r+')` + `sleep(1)` | 校验换成 `file_exists($p) && filetype($p) === 'socket'`：PHP 没有 `isSocket()` 这类函数（实测 `function_exists('isSocket') === false`），而 socket 文件的 `is_file()` 是 `false`，不能用它判。`filetype()` 对不存在的路径会报 warning，先判 `file_exists()`。`sleep(1)` 删除；响应直接由 `ControlClient` 同步返回后打印 |
| `StopCmd` / `RestartCmd` | 经由 `stopWorkerService()` | 无需改动，受益于返回值诚实 |
| [src/Cmd/DTO/CmdContext.php:104-105](../src/Cmd/DTO/CmdContext.php#L104) | `cliToWorkerPipe` / `workerToCliPipe` 两个字段读常量 | 合并为一个 `cliControlSocket`，由 `WORKER_PID_FILE_ROOT` 推导（`defined('WORKER_PID_FILE_ROOT')` 兜空串，保持现有容错风格） |

### 3.3 入口与 stub（应用侧文件）

现状 7 处重复定义同一组常量：

- 框架入口：[cli.php:60-61](../cli.php#L60)、[daemon.php:50-51](../daemon.php#L50)、[cron.php:51-52](../cron.php#L51)、[script.php:55-56](../script.php#L55)
- 生成模板：[src/Stubs/daemon.stub.php:53-54](../src/Stubs/daemon.stub.php#L53)、[src/Stubs/cron.stub.php:51-52](../src/Stubs/cron.stub.php#L51)、[src/Stubs/script.stub.php:54-55](../src/Stubs/script.stub.php#L54)

做法：**框架代码不再读取 `CLI_TO_WORKER_PIPE` / `WORKER_TO_CLI_PIPE`**，socket 路径统一由 `WORKER_PID_FILE_ROOT` 推导。

- 上述 7 处删掉两行 FIFO 常量定义，改为不涉及新常量（应用侧零新增要求）。
- 好处：已经存在的用户应用（`create` 时复制出去的旧 `daemon.php`，仍定义那两个常量）**不需要改一行**就能跑新版框架 —— 因为框架只认 `WORKER_PID_FILE_ROOT`，而它一直都在，且 `--group` 隔离已经编码在 `WORKER_SERVICE_NAME` 里（[function.php:134-152](../src/Core/Func/function.php#L134)，daemon 会追加 `Helper::daemonGroupInstanceSuffix()`）。
- 同时把 [BaseCmd.php:449-450](../src/Cmd/BaseCmd.php#L449) 的 `mkdir(..., 0777, true)` 收紧为 `0750`，并在 `install()` 里对新 socket `chmod 0700`。
- **`mkdir` 只影响新建目录**：已经存在的 `0777` 目录（老版本跑过的机器）不会被它改权限，必须显式补一次 `@chmod($dir, 0750)`。目录权限比 socket 权限更关键——目录可写就意味着同机其他用户能 `unlink` 本服务的 sock 再自建一个同名 socket，socket 上的 `0700` 挡不住这一步。
- 全仓只有 `BaseCmd.php:450` 一处建这个目录（`src/` 内无其它 `mkdir(WORKER_PID_FILE_ROOT...)`），而 `stream_socket_server` 要求父目录已存在。`install()` 里要自己兜一次：目录不存在则 `mkdir(0750)`，不能依赖「CLI 先跑过 start」。

### 3.4 测试与文档

| 位置 | 需要做什么 |
| --- | --- |
| [PHPUintTest/Unit/Worker/CliPipeDispatchTest.php:37-94](../PHPUintTest/Unit/Worker/CliPipeDispatchTest.php#L37) | 3 个 dispatch 用例保留（改为构造 `ControlRequest`），这是行为回归的锚点 |
| [PHPUintTest/Unit/Cmd/CmdCommandIntegrationTest.php:123-137](../PHPUintTest/Unit/Cmd/CmdCommandIntegrationTest.php#L123) | `testInfrastructureClassesExist` 断言里有 `FifoPipeClient::class`（[:129](../PHPUintTest/Unit/Cmd/CmdCommandIntegrationTest.php#L129)），换成 `ControlClient::class` |
| [PHPUintTest/Unit/Worker/PipeHandlerCallableTest.php](../PHPUintTest/Unit/Worker/PipeHandlerCallableTest.php) | 与传输无关（校验 worker pipe handler 可调用性），不动 |
| `docs/Cmd.md` | §2.3/§2.4 大段 FIFO 代码示例与「P1-架构：管道通信代码散落多处」的改造叙述要重写为 UDS 版本（[:80](../docs/Cmd.md#L80)、[:205-236](../docs/Cmd.md#L205)、[:468-507](../docs/Cmd.md#L468)） |
| `AGENTS.md` | 「先读哪里」表里 Daemon/Cron 一行补 `src/Worker/Runtime/CliControlServer.php` |
| `docs/Swoolefy-全框架Bug审计-20261024.md` | P1-04 详情补「修复与验证」、修复记录加一行 |

### 3.5 明确**不改**的部分

- Master ↔ 子进程 IPC：`ProcessIpc`（`Swoole\Process::$pipe` + `Event::add`，[:21-70](../src/Worker/Process/ProcessIpc.php#L21)）与 `AbstractBaseWorker` 的 `writeByProcessName` / flags。这套由 Swoole 自己管读写、只在本机父子进程间、且 `writeMessage` 自带分帧，与本次故障无关。
- `WORKER_STATUS_FILE`（status.log）与 `WORKER_CTL_CONF_FILE`（confctl.json）：文件通道，非 RPC。
- 信号路径：`SIGTERM/SIGUSR1/SIGUSR2/SIGHUP` 的语义与 `SignalShutdown` 的 5 步顺序。

### 3.6 `install()` 的三条前置规则（FIFO 时代不存在的问题）

现状 `install()` 是「路径存在就无条件 `@unlink`」（[:59-61](../src/Worker/Runtime/CliPipeServer.php#L59)），socket 不能沿用这个写法。

1. **陈旧 socket / 活实例占用**：`ctl.sock` 存在时用**连接探测**判断是否还有 listener——`stream_socket_client('unix://...', 0.2s)` 成功 → 抛 `RuntimeException` 让启动明确失败；`ECONNREFUSED` → `unlink` 后 bind。不能只看 `WORKER_PID_FILE`：`AbstractProcess::__start()` 在 `MainManager::start()` 之前就把本进程 pid 写进 pid 文件，陈旧 sock 会被误判成「本实例正在监听」，导致新实例明明没绑上却启动失败（容器 E2E 实际踩到）。活实例场景下删 socket 等于把它的控制通道偷走；连接探测才是「有没有人在听」的直接判据，pid 文件只用于错误文案。
2. **只删自己登记的**：关机第 4 步的 `unlink` 前比对 pid 文件里的 pid 是本实例，与 sidecar「只删本进程登记的 sock」纪律一致。
3. **路径长度前置校验**：`sun_path` 在 macOS 是 104 字节（含 NUL，可用 103），Linux 108（可用 107）。实测两个平台都不是「bind 失败」，而是**静默截断后 bind 成功**：

| 实测情况 | macOS / PHP 8.4.14 | 容器 `swoolefy-php85-v62`（Linux / PHP 8.5.8） |
| --- | --- | --- |
| 100 字节路径 | bind 成功，`file_exists()` 为真 | 同 |
| 104 字节（mac）/ 108 字节（linux）及以上 | `stream_socket_server` 返回**有效资源**，`errno=0`、`errstr=''`；内核按截断后的名字 bind，落盘文件名与请求路径不一致，`file_exists(请求路径)` 变 false | 同 |
| 两个不同长名截断到同一名字 | 第二个 bind 静默失败，依旧没有错误文案 | 同 |

后果有三个：CLI 侧的存在性检查误报「服务未运行」；关机时 `unlink` 删不掉，残留越积越多；`WORKER_SERVICE_NAME` 是 `APP_NAME + '-daemon' + '-' + 分组后缀`（多分组用 `+` 连接，[Helper.php:145-160](../src/Worker/Helper.php#L145)）拼出来的，一旦被截断到同一个前缀，**两个分组实例会抢同一个 socket，分组隔离直接破**。

规则：`install()` 生成路径后立即校验，`strlen($socketPath) > 100` 就抛 `RuntimeException`，文案带完整路径与实际长度。FIFO 没有这个约束，属于本次改造新增的失败模式（§7 有风险行）。真要支持超长服务名，只能把文件名换成服务名哈希，且 CLI 与 Master 必须用同一套算法——本次不做，先显式拒绝。

---

## 4. 新增模块（6 个，全部可单测）

```text
src/Worker/Runtime/CliControlServer.php     // 原 CliPipeServer 重写：listen/accept/分帧/连接上限/close
src/Cmd/Infrastructure/ControlClient.php    // 原 FifoPipeClient 替换：connect/写帧/读帧/超时
src/Worker/Runtime/ControlFrame.php         // 编解码：pack('N',len)+payload，纯静态，双端共用
src/Worker/Dto/ControlRequest.php           // 替代 PipeMsgDtoWorker（JSON 映射，值对象）
src/Worker/Dto/ControlResponse.php          // 统一响应信封 {ok,message,data,nonce}
src/Worker/Runtime/ReplySink.php            // 连接级回包对象：send(string)/close/isClosed
```

`ControlFrame` 的接口要能独立测（不需要 socket）：

```php
final class ControlFrame {
    public const MAX_REQUEST_BYTES  = 1048576;  // 1 MiB
    public const MAX_RESPONSE_BYTES = 1048576;  // 1 MiB，与请求同上限

    /** 写出完整一帧；短写视为失败并抛异常，绝不静默。 */
    public static function write($stream, string $payload): void;

    /**
     * 非阻塞读：凑齐一帧返回 payload；半包返回 null 并把残料留在 buffer；
     * 对端已关闭返回 false。
     * !! 必须把「半包」和「EOF」区分开：两者都返回 null 的话，客户端中途断开
     * 只能靠 30 秒 deadline 才清理，Master 白占一个连接位。
     */
    public static function tryRead($stream, string &$buffer): string|null|false;
}
```

`ReplySink` 的当前会话解析（为了不改 9 个 `responseMsgByPipe` 调用点）：

```php
// CliControlServer 在同步处理一个请求期间设置 $activeSink；
// dispatch 返回后立即清空。Master 事件回调内无协程抢占，因此该状态是安全的。
public function currentSink(): ?ReplySink;
```

**必须在代码注释里写清这条不变量**：处理链上不得出现「把回包推迟到下一个 tick」的写法；真要推迟，就必须把 `ReplySink` 显式捕获带走，并自己负责超时关闭。

---

## 5. 实施步骤（按可独立验收切分）

1. **编解码 + 值对象**：`ControlFrame` / `ControlRequest` / `ReplySink` + 单测（含半包、粘包、超长、短写）。此步不改任何现有链路。
2. **Worker 侧**：`CliControlServer` 落地，`MainManager::start()` 第 5 步与 `SignalShutdown` 第 4 步换类名；`StatusReporter::masterStatusToCli()`；`ProcessCommandHandler` 写 sink；按 §3.6 落地 install 三条前置规则。验收：`CliPipeDispatchTest` 等价用例 + 真实 UDS 握手单测（路径用 `/tmp` 短目录）+ 三个分支用例：陈旧 sock 能清、活实例占用会拒绝启动、路径超 100 字节抛异常。
3. **CLI 侧**：`ControlClient` 替换 `FifoPipeClient`，`sendWorkerPipeMessage` 返回值诚实化，`SendCmd`/`StatusCmd` 检查返回值并删掉 `sleep(1)`。验收：宿主机 `swoolefy status/stop/send` 手工跑通。
4. **CtlApi 接线** + 常量清理（7 处入口/stub 删 FIFO 常量）+ 权限收紧（目录 `0750`、socket `0700`；已存在的老 `0777` 目录要显式 `chmod`，`mkdir` 不会改它）。
5. **端到端**：见 §6 矩阵，macOS 宿主机 + 容器 `swoolefy-php85-v62`（Linux）各跑一遍；`composer test:unit` 全量对齐当前基线（993 项 / 10 个既有 `WORKER_PID_FILE_ROOT` 隔离错误 / 宿主机 0 失败；容器另有 3 个 root 权限相关失败）。
6. **文档**：`docs/Cmd.md`、`AGENTS.md`、审计报告 P1-04 关闭 + 升级说明。

---

## 6. 端到端验证矩阵

| 场景 | 命令 | 期望 |
| --- | --- | --- |
| 正常 status | `php daemon.php status App` | 打印 Master + Children 表；无 10 秒等待 |
| 优雅 stop | `php daemon.php stop App` | Worker Master 走 `shutdownMainManager`，pid 文件与 sock 文件都不残留 |
| send 启动未知进程 | `php daemon.php send App --name=NotExist --action=start` | **立刻**报错文案「找不到进程名…的配置项！」，非静默超时 |
| send 已知进程 start/stop/restart | 三条分别验 | 有回包文本，且 `status` 里进程状态随之变化 |
| 并发 CLI ×20 | 后台并发 `status`/`send` | 无交错帧；超出活跃连接上限的连接立刻收到 `busy`；Master 不假死 |
| CtlApi HTTP 触发 | HTTP 控制面 start/stop | 与 CLI 同效；无 CLI 连接时回包 no-op 不抛错 |
| 半包 | 客户端故意先写 2 字节再写余下 | 服务端不 dispatch，凑齐才执行 |
| 客户端连上就断 | `connect` 后立刻 `close`，一个字节都不发 | 服务端按 EOF 立即释放连接，不等 30 秒 deadline |
| 超长请求（>1 MiB） | 构造超大 `--msg` | 服务端拒绝并回 `ok:false`，连接关闭 |
| 陈旧 sock | `kill -9` Master 后重启同服务 | 新实例能 bind 成功，残留 sock 被替换，不留两个 socket 文件 |
| 活实例占用 | 服务在跑时手工往同目录塞一个同名 sock 再重启 | 后启动者明确失败并报出 pid，**不把前一个实例的 sock 删掉** |
| 超长 sock 路径 | 用长 APP_NAME 或多分组（`--group=group_a,group_b,...`）起 daemon | `install()` 抛异常并打印实际长度与完整路径，不是静默截断成别的名字 |
| 老目录权限 | 预先把 `WORKER_PID_FILE_ROOT` 建成 `0777` 再升级 | 启动后目录是 `0750`，socket 是 `0700`；非属主用户 `status` 明确失败 |
| 停机不打扰 | 无 Master 时 status/stop | 立刻明确「服务未运行」，不进入任何等待 |
| macOS vs Linux | 上述全量各一遍 | 行为一致（这正是本次要修的 #1 的平台差异） |

---

## 7. 风险与兼容性

| 风险 | 说明 | 处置 |
| --- | --- | --- |
| **滚动升级窗口** | `composer update` 后，还在跑的旧 Worker Master 只有 FIFO，新 CLI 只发 UDS | 不留 FIFO 回退（与你「不保留旧兼容」的既有取向一致）。`stop` 有 SIGTERM 兜底仍能停；`status`/`send` 会**立刻明确报错**而不是静默。升级说明写清：升级框架后必须先完整重启 Worker 服务 |
| Master 内误用协程 | 一旦引入 `Coroutine\Socket` 或协程定时器，后续 `fork` 报 `unable to create Swoole\Process with async-io threads`，属难查的启动期故障 | 方案在 §2.3 与代码注释里锁死「非协程实现」；单测断言 dispatch 期间 `Coroutine::getCid() < 0` |
| 权限收紧打断现网 | 目录从 `0777` → `0750`、socket `0700` 后，跨用户运维（A 用户起服务、B 用户跑 CLI）会失败 | 属于安全修复的必然代价，写进升级说明；若确实需要跨用户，用同一组（group）而不是放开 world-writable |
| `/tmp` 目录抢占 | 目录仍可被同用户进程抢先创建 | 本次只把模式收紧；`SO_PEERCRED`/`LOCAL_PEERCRED` 校验作为后续 P2 增强（两平台 API 不同，单独立项） |
| **`sun_path` 静默截断**（本次新增的失败模式） | 路径超限时 `stream_socket_server` 不报错、照常返回资源，内核按截断后的名字落盘：CLI 的存在性检查误判「服务未运行」、关机 `unlink` 删不掉、两个长服务名截到同一前缀会抢同一个 sock（分组隔离被破）。macOS/PHP 8.4 与容器 Linux/PHP 8.5 实测一致，见 §3.6 | `install()` 生成路径后 `strlen > 100` 直接抛异常并打印实际长度；§6 有对应场景 |
| 目录权限只对新建生效 | `mkdir(0750)` 不会改老部署留下的 `0777` 目录；目录只要是 world-writable，别人就能 `unlink` 本服务 sock 再自建一个，socket 上的 `0700` 挡不住 | 建目录处与 `install()` 都补一次 `@chmod($dir, 0750)`，失败只在 ctl.log 告警 |
| status 文本变大 | 现在无上限地 `fwrite` 多次；改为单帧后要受 `MAX_RESPONSE_BYTES` 约束 | 1 MiB 上限，超限由 ReplySink 回 `ok:false` 错误帧，行为比现在的静默更可控 |
| `PipeMsgDtoWorker` 退役 | 有应用可能引用它做自定义管道消息 | 保留 DTO 类并标 `@deprecated`？—— 不建议。按你的偏好直接删，`docs/Cmd.md` 里给出 `ControlRequest` 对照 |

---

## 8. 决策（均已按推荐方案落地）

1. **`status` 的响应形态**：§2.2 已按「统一信封，表格文本放 `data`」写定，好处是客户端只保留一套解析、渲染仍归 `StatusReporter`。若你更想要裸文本帧，只需回改 §2.2 响应那一处。
2. **并发上限取值**：应用层队列已删除（§2.4），串行由单线程事件循环天然保证；剩下要定的只是活跃连接上限，默认 32。真要让 `status` 不阻塞 `send`，得重审 `dispatch` 全部副作用的并发安全，代价大、收益小，不建议。
3. **权限收紧**：已按目录 `0750` + socket `0700` 落地；老 `0777` 目录在 `BaseCmd` 与 `install()` 两处 `chmod` 纠正。
4. **`PipeMsgDtoWorker` 处置**：已直接删除，未保留兼容层；`FifoPipeClient`、`CliPipeServer` 与两个 FIFO 常量同步删除。

### 8.1 开工前定的三处（已全部落地）

下面三条已在实现中按此口径落地：

1. **响应写出模型**：§2.3 让监听 fd 非阻塞，实测 `stream_socket_accept` 接到的连接也继承非阻塞（`stream_get_meta_data()['blocked'] === false`）。于是 §4 的「短写视为失败并抛异常」配上原来的 `MAX_RESPONSE_BYTES = 4 MiB` 一定炸——一次 `fwrite` 装不下几百 KB 的内核发送缓冲。反过来把连接设成阻塞，慢客户端会让 Master 卡在 `fwrite` 里，SIGCHLD、timer、accept 全停摆且 deadline 无法触发。需要的是「显式非阻塞 + 每连接 `out` 缓冲 + `Event::add($fd, $readCb, $writeCb, SWOOLE_EVENT_READ|SWOOLE_EVENT_WRITE)` 排空 + `out === ''` 才关」。实际落地：`ControlFrame` 提供 `encode()` / `write()`（CLI 阻塞写）/ `tryRead()`，Master 侧由 `ReplySink` 维护每连接 out 缓冲并在 EPOLLOUT 排空，响应上限降到 1 MiB。
2. **STOP 的回包契约**：[SignalShutdown.php:201-209](../src/Worker/Runtime/SignalShutdown.php#L201) 末尾是 `Event::exit()` + `$process->exit(0)`，STOP 分支一旦进入 shutdown 就再没有代码能回包；而 [ServerLifecycleManager.php:138-143](../src/Cmd/Application/ServerLifecycleManager.php#L138) 是拿「发送失败」决定是否走 SIGTERM 兜底的。所以必须写定：STOP 先直写小 ack 帧（小帧一定进得了发送缓冲）→ `fclose` → 再 `shutdownMainManager()`。同时补总规则「一个请求恰好一个响应帧」——`dispatchProcessCommand` 里存在完全不回包的分支（[:160-164](../src/Worker/Runtime/CliPipeServer.php#L160)），dispatch 返回时若未发货，服务端要补一帧 `ok:false` 再关。
3. **fork 继承连接 fd**：SEND_MSG 的 START 分支是先 `responseMsgByPipe()` 再 `startWorkerProcessCommand()`，后者经 [ProcessCommandHandler.php:103-112](../src/Worker/Runtime/ProcessCommandHandler.php#L103) 同步 fork。UDS 下此刻客户端连接还开着，每个新 worker 都会继承一份：常驻进程 fd 泄漏，且 Master `fclose` 后客户端看不到 EOF。规则定为「单帧即关」——`responseMsgByPipe` 写完这一帧立刻关闭连接，再执行会 fork 的副作用（现有调用顺序刚好允许）；`ControlClient` 侧则必须按帧长读数，绝不能读到 EOF 为止。

### 8.2 联调中补的四条生命周期修正

这四条不在原方案里，是容器 E2E 暴露出来的真实竞态，已落地：

1. **CLI 必须等 MainManager 退完再停外层 Server**。原 `stopWorkerService()` 收到 STOP ack 后立刻 `stopServer()` 给外层 Swoole master 发 SIGTERM；main manager 还在等业务 Worker 排空，外层 `max_wait_time`（Test 配置 10s）到点直接 SIGKILL 用户进程，`ctl.sock` / `worker.pid` 留在原地。现在 STOP 响应的 `data` 回传 `resolveShutdownWaitSeconds()`（本实例排空预算），CLI 按该预算等 MainManager 退出，再判断外层 Server 是否还活着，必要时才 `stopServer()`。
2. **MainManager 退出前主动停外层 Server，只等一个短窗口**。Swoole 会把退出的用户进程重新拉起：只发 SIGTERM 然后立刻 `process->exit(0)`，manager 收到 SIGCHLD 会先于 master 处理停机信号重新 fork，新实例马上重绑 `ctl.sock`。现在关机末尾由 `SignalShutdown::stopOuterServer()` 给外层 master 发 SIGTERM，并保持 MainManager 存活 `OUTER_SHUTDOWN_WAIT_SECONDS = 3` 秒后再退出。**不能等 master 死亡**：外层 master 在等 manager，manager 在等 MainManager，MainManager 再等 master 就是闭环，只能耗到 `max_wait_time` 被强杀。3 秒窗口只覆盖 master reactor 处理 SIGTERM、把 manager 带入 shutdown 的时间，足以避免 manager 重新 fork。
3. **外层 master pid 与 `WORKER_MASTER_PID` 必须对 respawn 安全**。首次 fork 出来的用户进程里 `Swfy::getServer()->master_pid` 是 0，拿不到外层 master；`MainManager::getOuterMasterPid()` 改为「先取 `$server->master_pid`，为 0 时回落到 `setting.pid_file`」。另外 `AbstractProcess::setWorkerMasterPid()` 原用 `getPid()` 读 Swoole Table，实例被重新拉起时表里还是上一个 MainManager 的 pid，会让 `inMasterProcessEnv()` 判自己为子进程、跳过 socket/sysvmsg 清理；改为 `posix_getpid()` 后每次 respawn 都写入真实 pid。
4. **`ctl.sock` 的归属按 inode 判断，不绑定 `worker.pid`**。respawn 场景下 pid 文件可能已指向新实例，而 socket 文件还是旧实例的；反过来 pid 文件陈旧时又会误删活实例的 socket。`install()` bind 成功后记录 `dev + ino`，`close()` 只在路径仍指向同一 inode 时 `unlink`，重绑同一路径的新实例不会被旧实例误删。

### 8.3 落地状态（容器 `swoolefy-php85-v62` 实测）

- `status` 0.3s 内返回；`send` 已知/未知进程、start/stop/restart 语义与 FIFO 版本一致。
- 20 并发 `status` 无交错；32 条连接占满后第 33 条立即收到 `busy` 帧。
- 半包、1 MiB 超长请求、连上即断、陈旧 sock 替换、活实例占用拒绝、超长路径拒绝均已实测。
- 优雅 stop（`--force=1`）退出码 0，`worker.pid` 与 `ctl.sock` 均不残留，无进程被重新拉起；随后 `start` / `restart` 可正常重新 bind。stop 全程约 13s：业务 Worker 排空约 2s、3s 关机窗口后 MainManager 退出，剩余约 7s 是外层 HTTP Worker 的既有退出超时（`Worker_reactor_try_to_exit ... forced termination`，`max_wait_time` 到点强制终止），refactor 前日志同样存在，与 UDS 控制面无关。
- `composer test:unit`：995 tests / 10 errors（既有 `WORKER_PID_FILE_ROOT` 隔离问题）/ 0 failures；容器内另有 3 个 root 权限相关失败，与本次改动无关。控制面专项 31 项全绿。

