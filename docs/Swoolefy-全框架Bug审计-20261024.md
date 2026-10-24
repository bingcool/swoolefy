# Swoolefy 全框架 Bug 审计（2026-10-24）

> **审计日期**：2026-10-24
> **代码分支**：`swoolefy-6.2-x`
> **审计基线**：HEAD `d93bad79`（含审计时工作区未提交改动）
> **运行环境**：PHP 8.4 / Swoole >= 6.1 / macOS arm64
> **审计方式**：只读静态审计 + 关键路径运行时验证
> **审计范围**：`src/Core`、`src/Http`、`src/Websocket`、`src/Tcp`、`src/Udp`、`src/Mqtt`、`src/Rpc`、`src/Worker`、`src/Cmd`、`src/AutoReload`、`src/Support/*`、`src/Stubs`，以及 `Test/` 下的运行配置
> **未覆盖**：`vendor/` 第三方实现、Go sidecar 二进制内部逻辑、压力测试与长时间稳定性测试
> **说明**：本次审计未修改任何框架或业务代码。

## 状态说明

| 状态 | 含义 |
| --- | --- |
| 未修复 | 已确认，尚未处理 |
| 修复中 | 已开始修改，尚未完成验证 |
| 已修复 | 代码已修改，待验证或已完成验证 |
| 已验证 | 修复后通过对应单测/运行时验证 |
| 不修复 | 误报、设计如此或暂不处理（需在备注写明原因） |

修复某个条目后，请同步更新本文对应条目的“状态”，并在文末[修复记录](#修复记录)追加一行。可用 `grep "未修复" docs/Swoolefy-全框架Bug审计-20261024.md` 查看剩余项。

## 总览

| 级别 | 数量 | 已修复 | 说明 |
| --- | ---: | ---: | --- |
| P0 | 3 | 3 | 安全/可用性关键缺陷，建议立即处理 |
| P1 | 7 | 5 | 高危缺陷，会稳定复现或造成安全风险 |
| P2 | 23 | 0 | 稳定性、正确性与可维护性问题 |
| **合计** | **33** | **8** | |

---

## P0：立即修复

### P0-01 MQTT 未完成 CONNECT 即可 PUBLISH 并写 retained

- **状态**：已验证
- **模块**：MQTT
- **位置**：[MqttReceiveDispatcher.php:169](../src/Mqtt/MqttReceiveDispatcher.php#L169)、[MqttReceiveDispatcher.php:448](../src/Mqtt/MqttReceiveDispatcher.php#L448)、[MqttBrokerSupport.php:47](../src/Mqtt/MqttBrokerSupport.php#L47)
- **问题说明**：
  V3 的 PUBLISH 分支直接调用 `handlePublish()`，而 `handlePublish()` 只对 QoS2 调用了带会话校验的 `rememberInboundQoS2()`。QoS0/QoS1 会直接执行 `MqttEvent::publish()`，最终由 `MqttBrokerSupport::dispatchPublish()` 写入 retained 并路由给订阅者。默认 `auto_protocol=false` 时，协议级别在检查会话之前就已返回，因此“必须先 CONNECT”没有被强制执行。
- **触发条件**：任意 TCP 客户端不发送 CONNECT，直接发送 MQTT PUBLISH（QoS0/QoS1），可携带 `retain=true`。
- **影响**：可向任意 topic 发布消息、污染 retained 消息、冒充业务设备；如果 ACL 只在 CONNECT 阶段实现，会被完全绕过。
- **修复建议**：在 dispatch 的 switch 前统一要求 `MqttSessionManager::requireConnected($fd)`，仅放行 CONNECT；PUBREL 同样校验。对未连接客户端直接关闭连接。
- **复核结论（2026-10-24）**：**确认存在**。
  - 运行时探测：以 `MqttSessionManager::get(9999)` 为 null（无会话）的 fd，手工构造 PUBLISH QoS1/retain=1 报文，调用 `resolveReceiveProtocolLevel($fd, $raw, ['protocol_level'=>4,'auto_protocol'=>false])` 返回 `4`，不抛异常，说明分发会继续走到 `handlePublish()`；同一 fd 调用 `requireConnected()` 则抛 `MQTT session is not connected`，证明校验器存在但未被 PUBLISH 路径使用。
  - 链路确认：`handlePublish()` → `MqttEventV3::publish()` → `MqttBrokerSupport::dispatchPublish()`（storeRetained + matchSubscribers）全程不读会话。
  - 默认配置确认：[conf.stub.php](../src/Mqtt/conf.stub.php) 中 `auto_protocol => false`、`username/password => ''`。`auto_protocol=true` 时未连接的非 CONNECT 报文会被 `resolveReceiveProtocolLevel()` 抛错关连接，这是唯一能挡住该路径的开关，但它不是默认值。
  - 补充：`ProductionMqttEventV3` 的用户名密码校验只挂在 `verify()/connect()`，即只对 CONNECT 生效，对绕过 CONNECT 的 PUBLISH 无效。
- **修复后验证（2026-10-24）**：项目内 `simps/mqtt 2.0.1` 下 MQTT 单测 35/35。另用真实 TCP 连接 swoolefy Broker（`127.0.0.1:1884`）端到端验证 11 项：未 CONNECT 的 retained PUBLISH 被立即断开；随后订阅同前缀收不到该 retained；正常 CONNECT/SUBACK、retained 下发与后订阅回放全部成功；服务端日志记录 `MQTT session is not connected` 并关闭该 fd。
- **置信度**：高。

### P0-02 Worker 服务子进程被信号终止后不会重新拉起

- **状态**：已验证
- **模块**：Worker（Daemon / Cron / Script）
- **位置**：[ProcessSupervisor.php:213](../src/Worker/Process/ProcessSupervisor.php#L213)
- **问题说明**：
  `Swoole\Process::wait()` 返回的 `code` 与 `signal` 是两个独立字段，这里只 `switch ($ret['code'])`。被信号终止的进程 `code` 恒为 0，信号只体现在 `signal` 中，因此所有非自愿信号退出都会落进 `case 0`，走删除 worker 分支，不重启。
  框架的自愿退出路径是通过实例方法 `Swoole\Process::exit($signo)` 用**退出码**表达的（[AbstractBaseWorker.php:743](../src/Worker/AbstractBaseWorker.php#L743) 停机、[AbstractBaseWorker.php:771](../src/Worker/AbstractBaseWorker.php#L771) 重启），所以 `case SIGTERM`（退出码 15）和 `case SIGUSR1`（退出码 10）是**真实可达**的正常分支，分别对应主动停机和主动重启；`case SIGKILL` 才是不可达的死分支，因为 SIGKILL 无法被捕获成退出码。
  另外没有第二条补活路径：[MainManager.php:403](../src/Worker/MainManager.php#L403) 的 `checkMasterToExit()` 在 Worker 服务里是空实现，worker 全部消失 Master 也不会退出或自愈；[StatusReporter.php:183](../src/Worker/Runtime/StatusReporter.php#L183) 周期性调用的仍是同一个 `rebootOrExitHandle()`。
- **触发条件**：worker 被 OOM killer、段错误、`kill -9` 或其他未捕获信号终止。
- **影响**：Daemon/Cron/Script 的 worker 数量永久减少，只能整服务重启恢复。协议服务通过 `addProcess` 注册的进程不受此逻辑影响，由 Swoole 自己管理。
- **修复建议**：优先读取 `$ret['signal']`；仅在主动停机标记存在时把 SIGTERM/SIGKILL 视为正常退出，其余信号一律走 `rebootWorker()`。补充 `kill -9` 子进程后自动拉起的集成测试。
- **复核结论（2026-10-24）**：**确认存在**。运行时实测 `Swoole\Process::wait()` 返回值：

  | 子进程结束方式 | wait 返回 | 命中的 case | 是否重启 |
  | --- | --- | --- | --- |
  | `exit(0)` | `code=0, signal=0` | `case 0` | 否（正常） |
  | 自愿停机 `swooleProcess->exit(15)` | `code=15, signal=0` | `case SIGTERM` | 否（正常） |
  | 自愿重启 `swooleProcess->exit(10)` | `code=10, signal=0` | `case SIGUSR1` | 是 |
  | `kill -9` | `code=0, signal=9` | `case 0` | **否（Bug）** |
  | SIGSEGV | `code=0, signal=11` | `case 0` | **否（Bug）** |
  | 未捕获 SIGTERM | `code=0, signal=15` | `case 0` | **否（Bug）** |

  即：信号退出与“正常退出”在 `code` 上完全不可区分，只有 `signal` 字段能区分，而该字段未被读取。
- **置信度**：高（已实测）。

### P0-03 Workflow 节点超时导致节点在无 App 上下文的子协程执行

- **状态**：已验证
- **模块**：Support/Workflow
- **位置**：[TimeoutGuard.php:37](../src/Support/Workflow/Engine/TimeoutGuard.php#L37)、[WorkflowEngine.php:379](../src/Support/Workflow/Engine/WorkflowEngine.php#L379)、[workflow.php:29](../Test/Config/workflow.php#L29)、[Application.php:22](../src/Core/Application.php#L22)、[StreamBridge.php:25](../src/Support/AI/Stream/StreamBridge.php#L25)
- **问题说明**：
  `TimeoutGuard::run()` 在 `$timeoutSeconds > 0` 时用裸 `Coroutine::create()` 执行回调（[TimeoutGuard.php:49](../src/Support/Workflow/Engine/TimeoutGuard.php#L49)），既没有拷贝协程上下文，也没有为新协程注册 Application。框架的对照实现是 [function.php:192](../src/Core/Func/function.php#L192) 的 `goApp()`：先 `Context::snapshot()` 再把可传播值写回子协程，并通过 `EventApp::run()` 绑定 Application。
  `Application::getApp()` 只按当前 cid 查表、没有父协程回退（[Application.php:55](../src/Core/Application.php#L55)），`Context::getContext()` 在协程内直接返回 `Swoole\Coroutine::getContext()`（每个协程独立，[Context.php:32](../src/Core/Coroutine/Context.php#L32)），`StreamBridge` 的 sink 也存在这个协程上下文里（[StreamBridge.php:75](../src/Support/AI/Stream/StreamBridge.php#L75)）。
  触发面很宽：`resolveNodeTimeout()` 会把没有显式配置超时的节点回退到引擎默认值（[WorkflowEngine.php:436](../src/Support/Workflow/Engine/WorkflowEngine.php#L436)），而 Test 配置 `default_node_timeout_seconds = 120`，于是**每个节点**都被包进子协程。框架默认 0 不触发。
- **触发条件**：Workflow 节点有效超时时间大于 0（节点配置或全局默认），且节点内读取协程上下文/组件/SSE sink。
- **影响（复核后收窄，见下方误报更正）**：
  - **流式节点 token 全丢**：`AINode::invokeStreamAgent()`（[AINode.php:193](../src/Support/AI/Node/AINode.php#L193)）与 `RAGNode`（[RAGNode.php:84](../src/Support/Rag/Node/RAGNode.php#L84)）在节点内调 `StreamBridge::emit()`；子协程无 sink，`emit()` 静默返回 false，SSE 一个事件都收不到，但接口不报错，属于最难发现的一类。
  - **节点内解析组件直接抛异常**：`SubWorkflowNode` 在节点协程内调用 `runner->run()` 与 `engine()->getRun()`（[SubWorkflowNode.php:73](../src/Support/Workflow/Node/SubWorkflowNode.php#L73)、[SubWorkflowNode.php:84](../src/Support/Workflow/Node/SubWorkflowNode.php#L84)），使用 DB/Redis RunStore 时会在这里触发 `WorkflowPdoResolver`/`WorkflowRedisResolver` → `Application::getApp()` 为 null → `WorkflowException`；RAG 检索经 [RagPdoResolver.php:28](../src/Support/Rag/Resolver/RagPdoResolver.php#L28) 同理。
  - **身份上下文丢失**：节点内 `FrameworkContext::user()/getTenantId()` 读到 null。框架自带 `PermissionPlugin` 在 `onRunStart`（引擎协程）校验，不受影响；这一条主要影响应用自写的节点，属于隐患。
- **修复建议**：`TimeoutGuard` 改用与 `goApp()` 相同的传递方式（Context snapshot + 为子协程注册 Application + 重新绑定 StreamBridge sink），或改为在同协程内用 `Timer`/`Coroutine::cancel` 实现超时，避免切换协程。
- **复核结论（2026-10-24）**：**确认存在**。运行时探测（父协程绑定 sink 并写入 `Context['probe_tenant']`）：

  | 场景 | cid | Context | StreamBridge sink |
  | --- | --- | --- | --- |
  | 父协程 | 1 | `tenant-1` | bound |
  | `TimeoutGuard::run(fn, 5.0)` 内 | 2 | **NULL** | **NULL**，`emit()` 返回 false，父协程收到 0 个事件 |
  | `TimeoutGuard::run(fn, 0.0)` 内 | 1 | `tenant-1` | bound（同协程直接执行，不触发） |

- **置信度**：高（已实测）。
- **同类面补齐（2026-10-24）**：同一 bug 面还有两处调用 `TimeoutGuard`。
  - [JobRunner.php:94](../src/Support/Job/JobRunner.php#L94)：Job Handler 的 `handler_timeout_seconds` 走同一个子协程超时，业务 Handler 里读租户上下文、解析 DB/Redis 组件同样会丢——**已改为 `runWithContext()`**。
  - [HealthProbe.php:129](../src/Http/Health/HealthProbe.php#L129)：健康探针只做连通性探测，不读请求上下文与流式 sink，**保持 `run()`**，避免给探针额外注册协程应用。
  - 验证：`JobRunnerTimeoutContextTest` 3/3（超时预算内 handler 看到 `Context`/`Application`/sink；父协程无 App 时不凭空绑定；超时 cancel 后子协程与临时 App 均不残留）；`JobHandlerTimeoutTest` 4/4 回归；unit+coroutine 全量 1060 项仅余 10 个既有 `WORKER_PID_FILE_ROOT` 隔离错误。

---

## P1：高危

### P1-01 JWT 自定义 claims 含标准声明时 `generateToken` 直接抛异常

- **状态**：已验证
- **模块**：Support/Auth
- **位置**：[JwtAuthGuard.php:207](../src/Support/Auth/JwtAuthGuard.php#L207)、[JwtAuthGuard.php:213](../src/Support/Auth/JwtAuthGuard.php#L213)、[JwtAuthGuard.php:148](../src/Support/Auth/JwtAuthGuard.php#L148)
- **问题说明**：
  `$reserved` 只排除了 4 个名字，且 `withClaim()` 位于 try 之外。library 的 Builder 对 `iat/exp/nbf/iss/aud/jti` 会抛 `RegisteredClaimGiven`，实测 `withClaim('iat', ...)` 直接抛出该异常，且不会被包装成 `AuthException`。
  更现实的触发路径是刷新 token：`authenticate()` 会把除 id/sub/roles/tenant 之外的所有 claims 放进 `AuthUser.claims`，其中包含 iat/exp；把这个 `AuthUser` 再传给 `generateToken()` 就会失败。
- **触发条件**：`AuthUser::claims` 中出现任一 JWT 注册声明，或业务自定义 claim 与注册声明重名。
- **影响**：登录/刷新接口 500；异常类型不符合鉴权层约定。
- **修复建议**：排除全部注册声明；标准字段只走 `issuedAt/expiresAt/issuedBy/permittedFor`；业务 claims 的写入包进 try 并抛出明确的 `AuthException`。`authenticate()` 不要把标准声明复制进 `AuthUser.claims`。
- **验证方式**：已用运行时脚本实测抛出 `RegisteredClaimGiven`；置信度高。
- **修复与验证（2026-10-24）**：`authenticate()` 的 claims 过滤排除全部 `RegisteredClaims::ALL`；`generateToken()` 忽略业务 claims 中的注册声明，`id_claim=sub` 时不重复写标准 sub，builder 写入与签名统一包进 try，异常统一转 `AuthException`。`AuthModuleTest` 10/10（新增 5 项，覆盖 claims 过滤、authenticate→generateToken 刷新、同名 claims 忽略、sub 映射、非法映射包装）。

### P1-02 Kafka 消费异常导致同一 offset 无限重试，阻塞整个分区

- **状态**：已验证
- **模块**：Support/Kafka
- **位置**：协议服务 [KafkaConsumerProcessHandler.php:99](../src/Support/Kafka/KafkaConsumerProcessHandler.php#L99)、常驻进程（Daemon/Cron）[KafkaConsumerWorkerProcessHandler.php:109](../src/Support/Kafka/KafkaConsumerWorkerProcessHandler.php#L109)
- **问题说明**：
  `atMostOnce=false` 时，`onMessage` 抛异常后只记录日志、`sleep(0.5)`、`continue`，`$pending` 不清空也不提交。下一轮继续处理同一条消息，没有重试上限、跳过或死信。`onHandleException()` 默认只记日志，不会中断循环（`BaseServer::catchException()` → `SwoolefyException::shutHalt()` 只写 error_log，不 rethrow、不退出）。
  两份 handler 是同一套循环逻辑的复制，`$pending` 非空后 `session->poll()` 不再被调用，`consume()` 长期不跑，rebalance 与 offset 回调也无人驱动；修复必须两处一起改。
- **触发条件**：某条消息持续触发业务异常（毒消息），且未开启至多一次模式。
- **影响**：该分区永久卡住，后续消息全部堆积，消费者形同停摆。
- **修复建议**：本地重试计数，超过阈值后提交偏移并投递 DLQ/记录丢弃；至少保证分区能继续前进。重试策略做成可配置。
- **验证方式**：静态确认；2026-10-24 复核两处 handler 与 `onHandleException()` 行为，确认存在。置信度高。
- **修复与验证（2026-10-24）**：新增共享 [ConsumeRetryLimit.php](../src/Support/ConsumeRetryLimit.php)（`$maxRetryAttempts`，0 或负数不限制；引入时默认 3 次 + 固定 0.5 秒，现已改为 5 次 + 指数退避，见下方「默认值复核与调整」）。两份 handler 在非 BEFORE_HANDLE 下累计同一条消息的失败次数，达到上限后调用新增的 `KafkaConsumeSession::logRetryExhausted()`（记录 topic/partition/offset/key/attempts），随后 `commitAfterHandled()` 提交该 offset 并继续消费；成功、换消息、丢弃时重置计数。`ConsumeRetryLimitTest` 通过。
  端到端在容器 `swoolefy-php85-v62`（PHP 8.5.8 + rdkafka 6.0.5）内对本机 Kafka 实测，协议服务版 13 项、Worker 版 8 项断言全部通过：依次投递 `good-1/poison/good-2`，毒消息恰好处理 3 次后记录一条 `kafka message dropped after retry attempts exhausted`（attempts=3、offset=1），随后 `good-2` 继续消费，消费组 committed offset 前进到 3；两版均使用默认 `$maxRetryAttempts`。注意容器不能直连 `192.168.60.83:9092`——broker 元数据会把它重定向到容器内的 `localhost:9092`，需使用 `host.docker.internal:29092`。
- **默认值复核与调整（2026-10-24）**：上面的端到端用的是「默认 3 次 + 固定 0.5 秒」，容忍窗口只有约 1.5 秒，下游数据库重启几十秒就会把正常消息判成毒消息 commit 丢弃——把「卡死分区」换成了「静默丢数据」。现改为默认 5 次 + 指数退避（`$retryBackoffBaseSeconds = 1.0` 起翻倍，单次封顶 `$retryBackoffMaxSeconds = 15.0`，即 1+2+4+8 ≈ 15 秒窗口），退避等待按 0.2 秒分片并响应停机标志（`!$this->running` / `$this->waitToExit`），停机不再被长等待放大；被放弃等待的这条既不提交也不丢弃，重启后由 broker 重投。丢弃仍是不可逆动作，需要更长故障容忍就加大 `$maxRetryAttempts`/上限，或设为 0 恢复无限重试。机制与 handler 接线未变，`ConsumeRetryLimitTest` 7/7、新增 `ConsumeRetryLimitWaitTest` 3/3；**旧参数下的 21/21 broker 端到端结论仍成立，新参数（5 次 + 退避）的 broker 复测待补**（需起 Test 服务协议服务，Kafka 还要进容器）。

### P1-03 AMQP 消费异常导致消息永不 ack/nack，prefetch=1 时队列卡死

- **状态**：已验证
- **模块**：Support/Amqp
- **位置**：协议服务 [AmqpConsumerProcessHandler.php:82](../src/Support/Amqp/AmqpConsumerProcessHandler.php#L82)、常驻进程（Daemon/Cron）[AmqpConsumerWorkerProcessHandler.php:85](../src/Support/Amqp/AmqpConsumerWorkerProcessHandler.php#L85)
- **问题说明**：
  异常分支保留 `$pending` 并 `continue`，既不 ack 也不 nack。`prefetch=1` 时 broker 不会继续投递，整个 consumer 停摆；代码中也没有 DLX 路径（DLX 基础设施在 `AmqpSettings::deadLetterExchangeType()` 有，只是异常重试路径不走它）。
  两份 handler 逻辑相同：handler 的 `$pending` 非空时不再 `poll()`；即便调用，[AmqpConsumeSession.php:36](../src/Support/Amqp/AmqpConsumeSession.php#L36) 也会把 session 里那条未确认的消息原样返回，所以重跑的永远是同一条。
- **触发条件**：消息处理持续抛异常。消费端没有「自动确认可关」这回事——`basic_consume()` 的 `no_ack` 实参在 [AmqpConsumeSession.php:93](../src/Support/Amqp/AmqpConsumeSession.php#L93) 硬编码为 `false`，只要走这条循环路径必然命中。
- **影响**：队列消费永久停滞；消息一直处于 unacked 状态，连接断开后才会重投。
- **修复建议**：异常时按重试次数 `nack(requeue: true/false)`；超限走 DLX 或记录后确认丢弃。补充毒消息测试。
- **验证方式**：静态确认；2026-10-24 复核两处 handler 与 `AmqpConsumeSession` 的 `no_ack`/prefetch 取值，确认存在。置信度高。
- **修复与验证（2026-10-24）**：两份 handler 使用共享重试上限；耗尽后记录 `amqp message dropped after retry attempts exhausted` 并 `nack(false)`，配了 `x-dead-letter-exchange` 的队列进死信，否则丢弃。`AmqpConsumeSession` 新增 `nack()`/`logDropped()`。用本机 RabbitMQ 实测 10 项全部通过：毒消息恰好处理 3 次后丢弃、队列继续消费后续消息；DLX 场景中毒消息进入死信队列且 payload 一致。
- **默认值复核与调整（2026-10-24）**：与 P1-02 同一处取舍，参数改法一致（默认 5 次、指数退避 1s 起翻倍封顶 15s、等待分片 0.2s 且响应 `waitToExit`）。未配 `x-dead-letter-exchange` 时超限消息仍是永久丢失，加大上限或设 0 由业务决定。`ConsumeRetryLimitTest` 7/7、`ConsumeRetryLimitWaitTest` 3/3；旧参数（3 次 + 固定 0.5s）下的 10/10 端到端结论成立，**新参数的本机 RabbitMQ 复测待补**。

### P1-04 macOS 上 FIFO `flock` 失败却返回 true，stop/send 静默失效

- **状态**：未修复
- **模块**：Cmd/Worker 运行时
- **位置**：[ServerLifecycleManager.php:208](../src/Cmd/Application/ServerLifecycleManager.php#L208)、[SendCmd.php:103](../src/Cmd/SendCmd.php#L103)
- **问题说明**：
  `sendWorkerPipeMessage()` 用 `@fopen(..., 'w+')` 打开 FIFO，`flock(LOCK_EX)` 在 macOS 的 FIFO 上会失败；代码不检查结果，仍然 `return true`，消息实际没有写入。`stopWorkerService()` 还有 SIGTERM 兜底，但 `SendCmd` 没有兜底，只能一直等待一个不会到来的响应。
- **触发条件**：macOS 本地执行 `swoolefy stop` 或向 Worker 发送 CLI 指令。
- **影响**：停止操作可能静默不生效；CLI send 卡住直到超时。
- **修复建议**：检查 `flock`/`fwrite` 结果，失败返回 false 并让调用方走信号或明确报错；或改用 Unix socket 传输。
- **验证方式**：代码路径确认，macOS 本地实测 `flock` 对 FIFO 失败；置信度高。

### P1-05 WebSocket 鉴权回调返回 true 或静态 token 时回退读 query `uid`

- **状态**：未修复
- **模块**：Websocket
- **位置**：[WebsocketAuthenticator.php:69](../src/Websocket/WebsocketAuthenticator.php#L69)、[WebsocketAuthenticator.php:132](../src/Websocket/WebsocketAuthenticator.php#L132)
- **问题说明**：
  `enable=false`、回调返回 `true`、静态 token 命中这三种情况都会用 `?uid=` / `?user_id=` 作为身份。只有回调返回 `['user_id' => ...]` 才是安全路径。业务回调很自然地 `return true` 表示鉴权通过，攻击者即可 `?uid=admin` 冒充任意用户。`docs/Auth.md` 已写明“禁止 return true”，但代码仍然允许。
- **触发条件**：WebSocket 鉴权开启，回调返回 `true`，或使用静态 token，或鉴权关闭。
- **影响**：任意用户身份伪造，后续点对点推送、权限判断全部失真。
- **修复建议**：删除 query 参数回退；回调必须返回带 `user_id` 的数组，否则拒绝连接。鉴权关闭时也不要用 query uid 作为可信身份。
- **验证方式**：静态确认；置信度高。

### P1-06 身份/租户可被客户端 Header 伪造，且 `setUser` 不清理旧值

- **状态**：已验证
- **模块**：Support/FrameworkContext、HTTP
- **位置**：[HeaderPropagator.php:69](../src/Support/HeaderPropagation/HeaderPropagator.php#L69)、[FrameworkContext.php:79](../src/Support/FrameworkContext.php#L79)、[HttpServer.php:194](../src/Http/HttpServer.php#L194)
- **问题说明**：
  `captureIncoming()` 没有任何可信来源判断，直接把 `x-user-id`、`x-tenant-id` 等白名单头复制进 HeaderContext；`getUserId()` / `getTenantId()` 在本地快照为空时回退读这些头。公网请求只要带 `x-tenant-id: 其他租户`，未强制登录的接口或内部调用链就会拿到攻击者指定的租户。另外 `setUser()` 在 `tenantId` 为空时不会清除旧的 `x-tenant-id`，同一协程内切换身份可能残留上一个租户。
- **触发条件**：HTTP 服务直接暴露公网，且接口没有强制登录，或业务代码调用 `FrameworkContext::getUserId()/getTenantId()` 而未先鉴权。
- **影响**：越权访问其他租户数据、伪造用户身份；服务间调用链会把伪造头继续向下游传播。
- **修复建议**：入口只信任来自可信代理/内网签名的传播头；业务身份读取不得回退到请求头；`setUser()` 每次成对写入或清除 user/tenant 头。
- **修复后验证（2026-10-24）**：
  - 入口信任边界：协议配置新增 `trusted_proxies`（IP/CIDR 数组，默认 `[]`）。`HeaderPropagator::captureIncoming()` 新增 `$trustedProxies` / `$remoteAddr` 参数，`remote_addr` 命中可信代理时才采信 `x-user-id`、`x-user-code`、`x-tenant-id`、`x-user-name`；否则从 HeaderContext 中剥离这四个身份头，并用真实 `remote_addr` 覆盖 `x-client-ip`。公网直连无法再用 Header 指定身份或租户。
  - 身份读取：`FrameworkContext::setUser()` 在有 tenant 时写 `x-tenant-id`，tenant 为空时显式删除旧值；`clearUser()` 同时删除 `x-user-id`、`x-tenant-id`。`getUserId()`/`getTenantId()` 的兜底只剩“可信透传头”。
  - 入口接线：[HttpServer.php:194](../src/Http/HttpServer.php#L194) 传入 `trusted_proxies` 与 `$request->server['remote_addr']`；`Test/Protocol/conf.php` 与 `src/Http/conf.stub.php` 增加默认空配置项。
  - 测试：新增 `HeaderPropagatorTest` 7/7（22 assertions，覆盖不可信剥离、可信代理保留、CIDR 不匹配、空值跳过等）；`AuthContextGoAppTest` 更新后 5/5（含 tenant 清空与 clearUser 移除身份头）；`OptionalAuthenticateMiddlewareTest` 7/7、`ApiRateLimiterMiddlewareTest` 2/2；unit+coroutine 全量 1050 项、0 失败，仅余 10 个既有 `WORKER_PID_FILE_ROOT` 隔离错误。
- **验证方式**：静态确认 + 单测；实际风险取决于部署是否直接暴露，置信度高。

### P1-07 simps/mqtt 2.x 严格 int 参数不兼容，CONNECT/SUBACK/PUBLISH 打包失败（复审新增）

- **状态**：已验证
- **模块**：MQTT
- **位置**：[MqttEventV3.php:138](../src/Mqtt/MqttEventV3.php#L138)、[MqttEventV3.php:164](../src/Mqtt/MqttEventV3.php#L164)、[MqttReceiveDispatcher.php:205](../src/Mqtt/MqttReceiveDispatcher.php#L205)、[MqttBrokerSupport.php:81](../src/Mqtt/MqttBrokerSupport.php#L81)
- **问题说明**：
  `composer.json` 声明 `simps/mqtt ^2.0` 后，2.x 的消息对象 setter 与 `PackTool` 参数改为严格 `int`，框架仍沿用 1.x 的传参方式：`ConnAck::setSessionPresent()` 传 bool，`PubAck/SubAck/UnSubAck::setMessageId()` 传 `$id ?? ''`，出站 PUBLISH 的 `dup/retain` 传 bool，V3 SUBACK 的 granted codes 传 `chr()` 字符串。严格模式下依次抛 `TypeError`，SUBACK 的 QoS 也会被打包成 0。
- **触发条件**：安装 simps/mqtt 2.x 后，任意 CONNECT 成功回包、ACK 或订阅下发。
- **影响**：CONNECT 后回 CONNACK 即抛 `TypeError` 并断连，MQTT 服务实际不可用；正常 PUBLISH 下发与 retained 回放同样失败。
- **修复建议**：在框架与 simps 的边界显式转 `int`，消息对象先 `(string)` 再发送；SUBACK 直接传 int codes。补充 V3 响应报文打包测试。
- **验证方式**：新增 `MqttV3ResponsePacketTest` 3/3；真实 TCP 端到端 11/11（见 P0-01 修复后验证）。
- **置信度**：高（已实测）。

---

## P2：稳定性与正确性

| 编号 | 模块 | 位置 | 问题说明 | 修复建议 | 状态 |
| --- | --- | --- | --- | --- | --- |
| P2-01 | MQTT | [MqttSessionManager.php:115](../src/Mqtt/MqttSessionManager.php#L115) | 重复 `client_id` 只 `remove($oldFd)`，不关闭旧 fd；旧连接仍留在 Server 中，叠加上面的 PUBLISH 问题可继续发包 | 显式关闭旧连接，再绑定新 fd | 未修复 |
| P2-02 | MQTT | [MqttSessionManager.php:201](../src/Mqtt/MqttSessionManager.php#L201)、[MqttSessionManager.php:538](../src/Mqtt/MqttSessionManager.php#L538) | 每连接订阅表条目数无上限；出站 QoS1/2 pending（`$outboundPending[$fd][$messageId]`）无条数上限与 TTL，客户端持续不确认会一直累积。注意：retain 与入站 QoS2 pending 已有配置上限（`retain_limits`、`qos2_pending`），不在此项范围内 | 增加 `max_subscriptions`、出站 pending 上限与 TTL，超限按协议错误断连 | 未修复 |
| P2-03 | Queue | [ListQueueConsumerProcessHandler.php:173](../src/Support/Queue/ListQueue/ListQueueConsumerProcessHandler.php#L173)、[DelayQueueConsumerProcessHandler.php](../src/Support/Queue/DelayQueue/DelayQueueConsumerProcessHandler.php) | `onMessage` 前就把 `$pending` 置空；SIGTERM 时 handler 执行超过 `shutdownWaitSeconds`，drain 看不到在途消息，消息丢失且不触发 `logLost` | handler 成功后再清空 pending，或保留在途引用供 drain 放回 | 未修复 |
| P2-04 | Nacos | [NacosRegisterServiceProcess.php:66](../src/Support/Nacos/NacosRegisterServiceProcess.php#L66) | `onShutDown()` 只在 `reboot()` 路径调用，SIGTERM 停机路径不注销实例，只能等心跳超时 | SIGTERM 停机流程也调用注销 | 未修复 |
| P2-05 | AMQP | [AmqpConsumeSession.php:34](../src/Support/Amqp/AmqpConsumeSession.php#L34) | 心跳只在 `poll()` 里泵；`onMessage` 执行时间超过 heartbeat 时连接会被 broker 断开 | 长任务中显式泵心跳，或调大 heartbeat | 未修复 |
| P2-06 | HTTP | [HttpRoute.php:395](../src/Http/HttpRoute.php#L395) | group middleware 返回 false 只 `break` 出中间件循环，controller 仍会执行；before middleware 返回 false 则会抛异常，语义不一致 | 统一 false 的短路语义，或在 group 分支同样中止调度 | 未修复 |
| P2-07 | HTTP | [HttpRoute.php:1006](../src/Http/HttpRoute.php#L1006) | `run_after_middleware` 在 group/before 中都匹配不到时，`parseLateMiddleware()` 的返回值被忽略，限流中间件被静默丢弃 | 匹配失败时报启动期错误，或追加到默认位置，不能静默丢弃 | 未修复 |
| P2-08 | HTTP | [Route.php:51](../src/Http/Route.php#L51) | 嵌套 `Route::group()` 结束后把 meta 重置为 `[]`，不是恢复外层 meta，内层会丢外层 meta | 保存并恢复上一层 meta，异常路径也要恢复 | 未修复 |
| P2-09 | Core | [SwoolefyException.php:116](../src/Core/SwoolefyException.php#L116) | 异常响应直接访问 `$app->swooleRequest->server`；请求尚未赋值时产生 warning，被转成 ErrorException，掩盖原始异常 | 访问前判空，缺失时回退到安全响应 | 未修复 |
| P2-10 | Core | [App.php:142](../src/Core/App.php#L142) | `parseHeaders()` / `initCoreComponent()` 在 `$this->swooleRequest` 赋值之前执行，组件闭包抛错会叠加上一条问题 | 先赋值 request，再初始化依赖 request 的组件 | 未修复 |
| P2-11 | Core | [ServiceDispatch.php:404](../src/Core/ServiceDispatch.php#L404) | `handleAfterRouteMiddles()` 吞掉所有 Throwable，仅留 `// todo`，后置逻辑失败不可见 | 至少记录错误日志，或按配置向上抛 | 未修复 |
| P2-12 | Core | [Session.php:157](../src/Core/Session.php#L157) | Cookie 原值直接作为 Redis key，无格式/长度校验、无前缀；可用于固定会话或探测同库其他 key | 校验 session id 格式、增加固定前缀、失败时重新生成 | 未修复 |
| P2-13 | Cmd | [PidFileManager.php:143](../src/Cmd/Infrastructure/PidFileManager.php#L143) | `return file_put_contents(...) >= 0;`，写入失败返回 false，而 `false >= 0` 为 true，调用方误以为 pid 已保存 | 改为 `!== false`，并检查写入字节数 | 未修复 |
| P2-14 | AutoReload | [Reload.php:128](../src/AutoReload/Reload.php#L128)、[Reload.php:204](../src/AutoReload/Reload.php#L204) | `reloading=false` 只在 else 分支恢复，自定义 `$reloadFn` 执行后标志一直为 true；每次 watch 都追加 `rootDirs`，reload 后数组翻倍 | 用 finally 恢复标志；watch 前去重或重建 rootDirs | 未修复 |
| P2-15 | Cron | [CronManager.php:409](../src/Worker/Cron/CronManager.php#L409)、[CronScheduler.php:68](../src/Worker/Cron/CronScheduler.php#L68) | 错过的调度点会在恢复后按 `max(1ms)` 连续补跑，可能形成瞬时风暴 | 合并错过的 slot，只执行最新一次或按策略跳过 | 未修复 |
| P2-16 | Core | [BaseServer.php:634](../src/Core/BaseServer.php#L634) | workerId→pid 映射存在 Swoole Table 单行 JSON 读改写，多 worker 同时启动会丢更新 | 拆成多行或使用原子字段/独立列 | 未修复 |
| P2-17 | Worker | [CliPipeServer.php:52](../src/Worker/Runtime/CliPipeServer.php#L52) | 一次 `fread(8192)` 就 `unserialize`，无长度帧、无循环读；大消息或合并写入会解析失败/丢命令 | 增加长度前缀与循环读，或改用 SOCK_SEQPACKET | 未修复 |
| P2-18 | Worker | [StatusReporter.php:66](../src/Worker/Runtime/StatusReporter.php#L66) | `file_put_contents` 非原子写，可能读到半截 JSON（读侧有容错，但状态会短暂缺失） | 临时文件 + rename 原子替换 | 未修复 |
| P2-19 | Core | [Tick.php:213](../src/Core/Timer/Tick.php#L213) | `after` 回调异常重新抛出时跳过 `updateRunAfterTick()`，`$_after_tasks` 残留已执行任务 | 在 finally 中更新任务表 | 未修复 |
| P2-20 | Core | [GoWaitGroup.php:256](../src/Core/Coroutine/GoWaitGroup.php#L256) | `waitWithErrorChannel()` 超时直接抛异常，不 cancel 已启动协程，后台协程继续跑并可能写入已结束的响应 | 超时后取消仍在运行的子协程并等待退出 | 未修复 |
| P2-21 | Workflow | [SubWorkflowNode.php:55](../src/Support/Workflow/Node/SubWorkflowNode.php#L55) | 子工作流递归调用无深度/环检测，配置错误会无限递归 | 增加最大递归深度与调用链环检测 | 未修复 |
| P2-22 | HTTP | [ResponseParseTrait.php:56](../src/Http/ResponseParseTrait.php#L56) | `returnJson` 无重复 end 保护，业务与框架各写一次时会触发 Swoole 警告/连接异常 | 增加 `isEnd()` 判断或统一由框架收口 | 未修复 |
| P2-23 | HTTP | [UploadedFile.php:147](../src/Http/UploadedFile.php#L147) | 使用调用方原始文件名时保留客户端扩展名，`validateExtensions()` 存在但不会自动执行；上传目录若可执行脚本会有风险 | 默认使用随机名，扩展名按白名单强制校验 | 未修复 |

---

## 修复顺序建议

1. **P0**：P0-01 MQTT 会话强校验 → P0-02 Worker 信号重启 → P0-03 Workflow 上下文传播。
2. **P1**：P1-01 JWT 注册声明 → P1-02/P1-03 毒消息策略 → P1-04 macOS FIFO → P1-05 WebSocket 身份回退 → P1-06 身份/租户头信任边界。
3. **P2**：先修数据丢失类（P2-03、P2-04）与限流静默失效（P2-07），再处理运维与内存增长项。

## 已排除/修正的误报

| 疑似问题 | 结论 |
| --- | --- |
| “Redis ListQueue/DelayQueue 的 SIGTERM 回调不是协程，drain 不会等待” | 误报。实测 Swoole 信号回调 `Coroutine::getCid() = 1`，drain 会等待；真实问题是 P2-03 的 pending 提前清空 |
| “`Process::wait()` 被终止进程的 code 就是信号值” | 误报。实测 SIGKILL 返回 `code=0, signal=9`，因此 P0-02 的判断依据是 `signal` 字段，不是 `code` |
| “JWT `withClaim('iat')` 只是覆盖已有值” | 误报。实测 library 直接抛 `RegisteredClaimGiven`，见 P1-01 |
| “P0-02 中 `case SIGTERM` 是死代码” | 表述有误，已更正。自愿停机走 `swooleProcess->exit(15)`，实测 `wait()` 返回 `code=15, signal=0`，`case SIGTERM` 可达；只有 `case SIGKILL` 不可达 |
| “MQTT retain 与入站 QoS2 pending 无上限” | 误报。`conf.stub.php` 已有 `retain_limits`、`qos2_pending`（max_count/max_bytes/ttl）；P2-02 只保留订阅表与出站 pending 无上限这一项 |
| “P0-03 下顶层 DB/Redis RunStore 会直接抛异常” | 表述过宽，已收窄。引擎自身的 `save/find/saveIfRevision` 发生在调用方协程，不受影响；只有在节点协程内间接访问 RunStore 的路径（`SubWorkflowNode` 调 `runner->run()`/`getRun()`）才会触发解析器异常 |
| “P0-03 会影响 PermissionPlugin 的角色校验” | 误报。`PermissionPlugin` 在 `onRunStart` 钩子里读 `FrameworkContext::user()`，运行于引擎协程；身份丢失只影响应用自写节点 |

## 本次审计的关键运行时验证

| 验证项 | 结果 |
| --- | --- |
| `Swoolefy\Library\Jwt` Builder 写入注册声明 | `withClaim('iat', ...)` 抛 `RegisteredClaimGiven` |
| `Swoole\Process::wait()` 对 SIGKILL 子进程的返回 | `['code' => 0, 'signal' => 9]` |
| Swoole 信号回调所在协程 | `Coroutine::getCid() = 1`，属协程上下文 |
| MQTT 无会话 fd 的协议级别解析（P0-01） | `resolveReceiveProtocolLevel()` 返回 `4`（V3）不抛错；同 fd 的 `requireConnected()` 抛 `MQTT session is not connected` |
| `Swoole\Process::wait()` code/signal 拆分（P0-02） | `exit(0)`→`0/0`；`swooleProcess->exit(15)`→`15/0`；`exit(10)`→`10/0`；SIGKILL→`0/9`；SIGSEGV→`0/11`；未捕获 SIGTERM→`0/15` |
| `TimeoutGuard` 的协程上下文传递（P0-03） | 父协程 cid=1 有 `Context['probe_tenant']` 与已绑定 sink；`run(fn, 5.0)` 内 cid=2 两者均为 NULL、`StreamBridge::emit()` 返回 false、父协程收到 0 个事件；`run(fn, 0.0)` 在同一 cid=1 执行且上下文完整 |

## 修复记录

| 日期 | 条目 | 提交 | 验证方式 | 备注 |
| --- | --- | --- | --- | --- |
| 2026-10-24 | P0-01 | c81bba9d | `MqttConnectGuardTest` 2/2；MQTT 单测套件 35/35；真实 TCP 端到端 11/11 | `dispatchV3()`/`dispatchV5()` 在 switch 前对非 CONNECT 报文统一 `requireConnected($fd)`；未连接连接被关闭并抛 `MqttProtocolException` |
| 2026-10-24 | P0-02 | c81bba9d | `ProcessSupervisorSignalExitTest` 5/5；Worker 相关回归 14/14 | `rebootOrExitHandle()` 优先读取 `signal`；主动停机（`SignalShutdown::isExiting()`）与动态缩容（`isStoppingDynamicPid()`）视为正常退出，其余信号一律 `rebootWorker()` |
| 2026-10-24 | P0-03 | c81bba9d | `TimeoutGuardContextTest` 3/3；`composer test:workflow` 全通过 | 新增 `TimeoutGuard::runWithContext()`：复制 `Context` 快照、重绑 `StreamBridge` sink、按需 `EventApp::run()` 注册 Application；`WorkflowEngine::executeNode()` 改用该入口 |
| 2026-10-24 | P1-07 | c81bba9d | `MqttV3ResponsePacketTest` 3/3；真实 TCP 端到端 11/11 | V3 响应消息对象改为 `(string)` 发送并显式转 int；出站 PUBLISH 的 `dup/retain` 转 int；SUBACK granted codes 直接传 int，修复 simps/mqtt 2.x 严格类型下的 `TypeError` 与 QoS 归零 |
| 2026-10-24 | P1-01 | c81bba9d | `AuthModuleTest` 10/10（新增 5 项） | `authenticate()` 过滤全部注册声明后再写入 `AuthUser::claims`；`generateToken()` 忽略业务 claims 中的注册声明，支持 `id_claim=sub`，写入/签名异常统一包成 `AuthException` |
| 2026-10-24 | P1-02 | c81bba9d | `ConsumeRetryLimitTest` 3/3；容器 `swoolefy-php85-v62` + 本机 Kafka 端到端 21/21（协议服务版 13、Worker 版 8）；unit+coroutine 全量 1041 项仅余 10 个既有 `WORKER_PID_FILE_ROOT` 隔离错误 | 两份 Kafka handler 增加 `$maxRetryAttempts`（默认 3，0/负数不限制），同一 offset 连续失败达上限后记录 `kafka message dropped after retry attempts exhausted` 并提交 offset 丢弃，避免毒消息无限阻塞 partition |
| 2026-10-24 | P1-03 | c81bba9d | `ConsumeRetryLimitTest` 3/3；本机 RabbitMQ 端到端 10/10（含 DLX） | 两份 AMQP handler 达重试上限后 `AmqpConsumeSession::nack(false)` 放弃消息并记录 `amqp message dropped after retry attempts exhausted`，未配置 DLX 时消息被丢弃、配置了 DLX 时进入死信队列；处理成功后重置计数 |
| 2026-10-24 | P1-06 | c81bba9d | `HeaderPropagatorTest` 7/7；`AuthContextGoAppTest` 5/5；unit+coroutine 全量 1050 项仅余 10 个既有 `WORKER_PID_FILE_ROOT` 隔离错误 | 新增 `trusted_proxies` 配置门控身份传播头，不可信来源剥离 `x-user-id`/`x-user-code`/`x-tenant-id`/`x-user-name` 并用 `remote_addr` 覆盖 `x-client-ip`；`FrameworkContext::setUser()` 成对维护 user/tenant 头、`clearUser()` 删除两者；`HeaderContext` 新增 `remove()` |
| 2026-10-24 | P1-02 / P1-03 默认值复核 | c81bba9d | `ConsumeRetryLimitTest` 7/7；`ConsumeRetryLimitWaitTest` 3/3；unit+coroutine 全量 1057 项仅余 10 个既有 `WORKER_PID_FILE_ROOT` 隔离错误 | 默认从「3 次 + 固定 0.5 秒」（约 1.5 秒窗口）改为「5 次 + 指数退避 1s 起翻倍封顶 15s」（约 15 秒窗口），避免下游瞬时抖动被判定为毒消息后不可逆丢弃；退避等待按 0.2 秒分片并响应停机标志，Kafka/AMQP 共 4 处 handler 接线同步；README 补充调参指引。新参数下的 broker 复测待补 |
| 2026-10-24 | P0-03 同类面（JobRunner） | c81bba9d | `JobRunnerTimeoutContextTest` 3/3；`JobHandlerTimeoutTest` 4/4；unit+coroutine 全量 1060 项仅余 10 个既有 `WORKER_PID_FILE_ROOT` 隔离错误 | `JobRunner::run()` 的 Handler 超时改用 `TimeoutGuard::runWithContext()`，消费进程内的 Handler 恢复 `Context` 快照、`StreamBridge` sink 与协程应用；`HealthProbe` 按结论保持 `run()`（探针不读上下文） |

> 验证说明：完整 `composer test` 有 10 个与本次修复无关的既有错误（`Undefined constant "WORKER_PID_FILE_ROOT"`，测试隔离导致，HEAD 基线同样复现；宿主机 PHP 8.4 全量 1041 项为 10 错误、0 失败）。在容器 `swoolefy-php85-v62`（PHP 8.5.8）内跑同一套件也会完成，额外只有 3 个依赖只读目录的用例失败，原因是容器以 root 运行、权限位不生效；这 3 个类在宿主机非 root 下 25/25 通过。为消除 PHP 8.5 对 `ReflectionProperty::setAccessible()` 的弃用（在 Swoole 协程栈上会触发 PHPUnit fatal 中断），已移除 `src/` 中 9 处无效调用。这里确认**保持删除、不加 `method_exists` 或版本分支**：该方法自 PHP 8.1 起就没有作用（8.5 的弃用原文即 `has no effect since PHP 8.1`），而它在 8.4 与 8.5 上都存在，`method_exists` 恒为真、区分不出有效性；`composer.json` 要求 `php: ^8.4`，删除后 8.4 与 8.5 的行为完全一致（宿主机 8.4 全量与容器 8.5 均已实测私有属性反射读写正常）。MQTT 复测使用项目内 `simps/mqtt 2.0.1`；emqx 容器（1883）仅做了客户端 CONNACK 连通性检查，P0-01 的真实端到端验证跑在 swoolefy 自身 Broker（1884）上，因为框架 MQTT 模块本身就是 Broker。
