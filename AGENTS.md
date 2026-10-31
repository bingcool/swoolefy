# AGENTS.md

这份文件给在本仓库里改代码的代理用。先看这里，再下钻源码、`docs/` 与模块 `README.md`。它不是 API 手册；与源码冲突时以源码为准，并顺手修本文件。

## 仓库是什么

swoolefy 是基于 Swoole 的常驻内存协程框架。当前主线 **6.2**（分支 `swoolefy-6.2-x`）。

- PHP `^8.4`，`ext-swoole >= 6.1`
- 命名空间 `Swoolefy\` → `src/`（Composer PSR-4）
- 协议服务：HTTP、WebSocket、TCP、UDP、MQTT，RPC 是 TCP 之上的封装
- Worker 服务：Daemon 常驻、Cron 调度、Script 一次性脚本
- 业务样例 `Test/`，命名空间 `Test\`，不进 Composer PSR-4，由 `Test/Autoloader.php` 按目录加载

入口脚本的现状容易搞错，先看清：

| 文件 | 状态 |
| --- | --- |
| `swoolefy` | Symfony Console 内核，**git 跟踪**。被 `cli.php:55` include，不单独执行 |
| `cli.php` | HTTP / WebSocket / TCP / UDP / MQTT 入口。**在 `.gitignore` 里**，不进提交 |
| `daemon.php` `cron.php` `script.php` | Worker 服务入口。同样被忽略，由 `php cli.php create` 从 `src/Stubs/{daemon,cron,script}.stub.php` 复制到启动目录，只在缺失时生成 |

日常入口是 `php cli.php <command> <AppName>`，命令：`start` `stop` `restart` `reload` `status` `send` `monitor` `create`（实现见 `src/Cmd/Command/`）。

两个经常一起改的独立仓库，和本仓库同级：

| 仓库 | 作用 |
| --- | --- |
| `../library`（`bingcool/library`，分支 `library-6.x`） | DB、Redis、Queue、Uuid、Oauth 等协程组件，Composer path repository 依赖 |
| `../swoolefy-go-sidecar`（分支 `main`） | Go sidecar。产物放进 `src/Core/Sidecar/Bin/`，PHP 与二进制必须一起发 |

不要在一次改动里偷偷改 library 的行为却只改 swoolefy。跨仓库协议字段（sidecar JSON、Uuid Lua）要两边同时看。

## 目录地图

```text
swoolefy              Console 内核（命令在此定义）
cli.php               协议入口，本地生成，不提交
src/
  Core/               服务器基座、事件钩子、App 容器、协程、进程、组件调度、Sidecar
  Http/ Websocket/    各协议 Server + EventServer + Handler + 路由/中间件
  Tcp/ Udp/ Mqtt/ Rpc/
  Worker/             Daemon / Cron / Script 的 MainManager、控制面与调度引擎
  Cmd/                命令实现；CreateCmd 从 Stubs 生成应用
  Stubs/              create 复制的模板
  Support/            业务能力，见下方模块表
  Script/ Annotation/ AutoReload/ DataStruct/ EventInterface/ Exception/ Util/
Test/                 可运行的样例应用（APP_NAME=Test）
PHPUintTest/          PHPUnit 11。目录名就是这个拼写，不要改成 PHPUnit
docs/                 方案与说明，区分「现行规范」和「历史快照」
dockerfiles/          本地 PHP 镜像与本地依赖 docker-compose.yml
```

`src/Core/` 没有 `Config/`；配置入口是 `src/Support/ApplicationConfig.php` 与 `src/Core/SystemEnv.php`。Core 子目录：

| 目录 | 做什么 |
| --- | --- |
| `BaseServer.php` `EventCtrl.php` `EventHandler.php` | Server 基座、启动钩子、默认事件实现 |
| `App.php` `Application.php` `AppDispatch.php` `ServiceDispatch.php` | 单请求容器、协程内 App 注册表、分发 |
| `Process/` `ProcessPools/` `Task/` `Timer/` `Table/` `Memory/` | 自定义进程、进程池、异步任务、定时、Swoole\Table、原子计数与 Channel |
| `Coroutine/` `Crontab/` `Schedule/` | 协程上下文与并发工具、进程内 cron、调度 DSL |
| `Sidecar/` `Runtime/` `SysCollector/` | Go sidecar、Worker 本地可观测性、系统指标 |
| `Controller/` `Dto/` `Log/` `Func/` | 控制器基类、DTO 基座、日志、全局函数 |

协议目录里认这几条链路就够了：`HttpServer` → `HttpAppServer::onRequest`；`WebsocketServer` → `WebsocketEventServer` → `WebsocketHandler`；`UdpServer` → `UdpEventServer` → `UdpHandler`；`TcpServer`（`RpcServer` 继承它）→ `RpcHandler`；`MqttServer` → `MqttReceiveDispatcher`。子目录按功能拆：`Http/Health` `Http/Middleware` `Http/OpenTelemetry` `Http/RateLimit`；`Websocket/Cluster`（集群推送，31 个文件）`Websocket/Group` `Websocket/Offline` `Websocket/Push` `Websocket/SocketIO`（含 `Polling/`）`Websocket/Metrics`。

`src/Worker/` 是 Worker 服务的运行时：`MainManager.php` 组合根，`AbstractMainProcess` / `AbstractWorkerProcess`，`CtlApi.php` 控制面，`Runtime/CliControlServer.php` UDS 控制，`Cron/` 是 41 个文件的调度引擎（`CronManager`、`CronScheduler`、Shell/Http/Kubernetes Executor），`Process/` 管 IPC 与进程登记，`Config/WorkerConfLoader.php` 读 worker 配置。

`src/Cmd/` 19 个文件：`BaseCmd` + `CreateCmd`，`Command/` 七条命令，`Infrastructure/` 管 pid 文件、控制客户端、状态渲染，`Application/ServerLifecycleManager.php` 管生命周期，`Support/ProcessTreeTerminator.php` 收进程树。`src/Stubs/` 48 个模板：入口（`daemon`/`cron`/`script`/`event_handle`/`Bootstrap`/各协议 `*Server`）、配置（`app.conf`/`dc`/`db`/`cache`/`log`/`job`/`workflow`/`sidecar`/`otel` 等）、业务（`api`/`ChatService`/`MessagePushEnricher`/Websocket 回调/translations）。

其余目录：`Annotation/`（Api 文档注解 + `Validation/` 校验规则）、`AutoReload/`（inotify 热重载）、`DataStruct/`（ArrayCollection 等）、`EventInterface/`（RPC/UDP/WebSocket 事件接口）、`Exception/`（19 个异常类）、`Script/`（`Kernel`、`MainCliScript`、生成 ApiDoc/Cron/Daemon 脚本，`Script/ApiDoc/` 是文档生成）、`Util/`（Helper、IpUtils、`ArrayHelper/`）。

非主路径，别当运行时入口：`src/Websocket/Tests/`（Socket.IO 测试页）、`src/Support/Tests/`（单测夹具）、`src/Core/Sidecar/Bin/`（预编译二进制）、根 `Test/` 与 `PHPUintTest/`。

## 服务类型

`swoolefy` 在 Console 启动时定义判定函数（`swoolefy:23-58`），常量由各入口脚本自己 `define`（`cli.php:34-37`、`src/Stubs/{daemon,cron,script}.stub.php` 各三行）。用函数判断，不要猜进程名。

| 函数 | 何时为真 |
| --- | --- |
| `isDaemonService()` | 入口定义 `IS_DAEMON_SERVICE = 1` |
| `isCronService()` | 入口定义 `IS_CRON_SERVICE = 1` |
| `isScriptService()` | 入口定义 `IS_SCRIPT_SERVICE = 1` |
| `isWorkerService()` | 上面三个任一为真 |
| `isCliService()` | 就是 `!isWorkerService()`。**console / status / 测试进程也为真**，不能拿它当「协议服务」用 |

三者互斥，`cli.php` 还额外定义 `IS_WORKER_SERVICE = 0`（框架代码不读）。另有一个 `isDaemon()`，读环境变量 `daemon`，判断的是「是否守护进程化」，与上面的服务类型无关。

Sidecar 的 socket 子目录按 `cron` → `script` → `daemon` → `cli` 取值（`src/Core/Sidecar/SocketEndpoint.php:33-46`）。

k8s Job 跑 script 时会带 `--fromk8s`。`SystemEnv::isFromK8s()` 为真时，`printStartInfo()` 对 script 直接返回；`showLogo` 对任意命令的 `--fromk8s` 都跳过。

## 启动与事件

`EventCtrl::init()` 在 `BaseServer::__construct()`（`src/Core/BaseServer.php:145-171`）里调用，所有协议服务与 Worker 入口共用，发生在 `start()` 之前，跑在 Master，没有协程。顺序固定（`src/Core/EventCtrl.php:41-97`）：

1. Script 服务：`MainCliScript::parseClass()`。
2. 建 `LOG_PATH`，注册日志组件。`daemon` / `cron` / `cronScheduleScriptModel` 走 `AbstractWorkerProcess::registerLogComponents()`，其余走 `SystemEnv::registerLogComponents()`。
3. 非 Worker：Http 走 `Route::loadRouteFile()`，Websocket / Udp 走 `ServiceDispatch::loadRouteFile()`。
4. `onInit()`。应用在这里做慢 SQL、租户拦截器、组件预注册这类全局初始化。
5. `registerSqlLogger()` → `registerCrontabLogger()` → `registerGuzzleCurlLogger()`，非 Script 再 `registerStartLog()`。
6. 非 Worker 才注册框架 user process，按开关逐个判断：系统采集、热重载、Nacos 注册、Nacos 配置变更监听、WebSocket 集群推送。两个 Nacos 开关分别对应 `enable_nacos_register` 与 `enable_nacos_config_change_restart`（`ApplicationConfig`），应用不必在 `Event` 里手动 `addProcess`。
7. Worker 走 `onWorkerServiceInit()` + `boostrapWorkerInit()`：校验 `WORKER_SERVICE_NAME` / `PROCESS_CLASS` 后 `addProcess` 主管理进程。
8. `SidecarSupervisor::attach()`：登记 sidecar、按需拉起 Go 进程。
9. 只有 `BaseServer::getServer()` 是 `Swoole\Server` 实例时才 `bindShutdownHook()`。
10. `onStartRegisterProcess()`：协议服务的自定义进程写在这里。
11. `printStartInfo()`。

应用类是 `Event extends EventHandler`，`EventHandler extends EventCtrl`。`create` 从 `src/Stubs/event_handle.stub.php` 生成 `Event.php`，`onStartRegisterProcess()` 是空实现加 `// todo`。

自定义进程用 `ProcessManager::getInstance()->addProcess(名字, 类名, ...)`。同一个消费组加 Topic 只登记一个拉取进程。`addProcess` 发生在 `start()` 之前，这里拿不到 pid。`onInit()` 技术上也能 `addProcess`（框架没有状态校验），但约定统一放 `onStartRegisterProcess()`，别两处都写。

Worker 服务不使用这套自定义进程表。Daemon 写在 `WorkerDaemon/worker_daemon_conf.php`，按 `group_1` 这类分组 `include conf/*.php`；Cron 任务写在 `WorkerCron/conf/*.php`（另有 `Yaml::parseFile(APP_PATH.'/cron.yaml')` 的 YAML 模式，示例在 `schedule_fork_conf.php` 里被注释）。字段至少 `process_name`、`handler`、`worker_num`；`max_handle`、`life_time`、`limit_run_coroutine_num` 有默认值。

## 请求链路

HTTP：`onRequest` → `App` 构造并 `run()` → `HttpRoute::dispatch()` → 前置中间件 → Controller（继承 `BController`）→ 后置中间件 → `App::end()` 归还连接池。协程里用 `goApp()` 隔离 DB / Redis / Curl。限制并发用 `Parallel`，等待一组协程用 `GoWaitGroup`。

组件是协程单例，请求结束必须归还池子。不要把连接、`SidecarClient`、可写的 DTO 存进跨协程的静态属性。跨请求身份用 `FrameworkContext`（Auth + 可信头），不要自己搓静态变量。

## 配置与环境

| 位置 | 内容 |
| --- | --- |
| `Protocol/conf.php` | 协议层：`event_handler`、`application_bootstrap`、`exception_handler`、`response_formatter`、进程名、`host`/`port`、`SidecarConfig::fromApp()`、`trusted_proxies`、`runtime_observability` |
| `application.yaml` | 应用级行为，目前主要是 Nacos 注册/发现/配置变更；支持 `${ENV}` 占位。由 `ApplicationConfig` 与 `Config/*.php` 一起读 |
| `Config/app.php` | `app_conf`，`SystemEnv::loadAppConf()` 实时读盘 |
| `Config/dc.php` | 组件清单，`SystemEnv::loadDcEnv()`；组件实现放 `Config/component/*.php`，一个组件一个文件 |
| `.env` | `SystemEnv` 载入 Dotenv；**不会自动进入子进程**，sidecar 要的变量由 `attach()` 在 `exec` 前 `putenv` |
| `nacos.yaml` | Nacos 连接（host/port/namespace），`NACOS_FILE_PATH` 可覆盖；服务注册等行为在 `application.yaml` |

环境名：`SWOOLEFY_CLI_ENV` ∈ `dev` / `test` / `gra` / `prd`，校验后定义 `SWOOLEFY_ENV`（`src/Cmd/BaseCmd.php:230-242`）。协议与采集相关常量在 `src/Constants.php`；`APP_NAME` / `APP_PATH` / `WORKER_PORT` 由入口脚本定义，`APP_META_ARR` 决定每个应用名对应的协议与端口。

不要提交 `Test/.env`、`Test/Storage/`、`Test/sidecars_sock/`、`*.log`、`*.sock`（`.gitignore` 已覆盖）。读 `.env` 时只查需要的那一行，不要把文件内容打出来。

## Sidecar 与 RocketMQ

PHP 不连 RocketMQ。Master 在 `attach()` 里 `addProcess` 一个 Go 进程，`exec` 替换成 `Bin/go-sidecar-*`。Worker 和消费进程通过 Unix socket 发帧：4 字节大端长度 + 一个 JSON 对象（`FrameCodec.php`）。单条连接不做多路复用，并发来自多个协程各自建连接，在途数受 `sidecar_max_inflight` 限制。

硬约束：

- 一项 `SidecarSpec` 等于一个操作系统进程，等于一个 socket。不要让两个声明共用一个 socket。
- `sidecar_socket_path` 只能是目录，不能是 `.sock` 文件。空字符串用 `{sidecar_runtime_dir}/sidecars_sock`。框架再追加服务子目录，业务不要自己再写一层 `cli`，否则会变成 `cli/cli`。写成 `.sock` 文件直接启动失败。
- 文件名由框架生成：`{APP_NAME}-{Ymd}-{His}-{port}-{name}.sock`。`Ymd-His` 每个进程只取一次。
- 路径上限：macOS 103 字节、Linux 107 字节，都不含结尾 NUL，超了启动失败。测试用短的 `/tmp/...`，不要用 `sys_get_temp_dir()`（macOS 上那条路径会超限）。
- `sidecar_clean_sockets_on_start` 默认 `false`。只有显式打开时，才在 `attach()` 前删除当前服务目录里 200ms 内连不上的 `.sock`。目录本身、普通文件、符号链接、别的服务目录不动。服务目录名不是 `cli` / `cron` / `script` / `daemon` 时直接拒绝。
- 本进程 shutdown 时只 `unlink` 自己登记过的 sock，失败不抛错，文件留下。`BeforeShutdown`（Master）和 `ManagerStop` 各做一次；MQTT、WebSocket 在 `graceful_shutdown` 开启时**追加**一条自己的回调，回调首行已调用 `unlinkOwnSockets()`，不要删掉这两处调用。
- 不要用未加 Master pid 判断的 `register_shutdown_function` 去删 sock。`start()` 之前注册的 shutdown 函数会被 fork 进 Worker，Worker 退出时会删掉还在用的 socket。
- `call()` 必须在协程里。第一个字节写出之前失败可以换连接重试；写出之后失败是 `OutcomeUnknownException`，对端可能已经执行，`rocketmq.producer.send` 不能自动再发一次。
- Go 用 `DisallowUnknownFields` 解请求。PHP 字段名和二进制必须一起发布。协议里是 `keys`，不是 `key`。
- `$consumerTag` 在 PHP 基类上是 `array`，例如 `['paid', 'cancel']`。发给 Go 之前转成 `paid || cancel`。含 `*` 或空数组表示全部。不要把子类改回 `string`。
- `$consumerSql` 与具体 Tag 互斥。SQL92 需要 broker `enablePropertyFilter=true`。过滤在 broker 上做，被滤掉的消息对该消费组算已消费。
- 同一个消费组加 Topic 同时只能有一个进程在 `receive`，第二个立刻得到 `OVERLOADED`。协议服务用 `RocketmqConsumerProcessHandler`，Daemon / Cron 常驻用 `RocketmqConsumerWorkerProcessHandler`，`worker_num` 保持 1。两条路径不要订阅同一个消费组加 Topic。
- SimpleConsumer 每次 `Receive` 只挂一个队列。`awaitDuration` 是 `5 * time.Second`（`swoolefy-go-sidecar/internal/rocketmq/sdk_consumer.go`），加上 SDK 默认 3 秒请求超时，Proxy 算出的长轮询约 6.5 秒且不短于 5 秒（`grpcClientConsumerMinLongPollingTimeoutMillis`）。再缩短客户端截止时间会让 Proxy 返回 `BAD_REQUEST`。不要在一次 PHP `receive` 里循环多次 `Receive` 扫队列，那会把几轮 5 秒加总成二十多秒。
- 消费确认失败只记日志。未确认的消息在 `invisibleMs` 之后重投。`changeInvisible` 之后的 ack 在 RocketMQ 5.3.x proxy 上可能不生效（apache/rocketmq#9583）。长任务优先把 `$invisibleMs` 设够，业务按 `messageId` 幂等。

框架自带的 sidecar 声明在 `src/Core/Sidecar/Config/SystemSidecar.php`。应用追加写 `Config/sidecar.php`，顶层只允许 `sidecar_runtime_dir`、`sidecar_clean_sockets_on_start`、`sidecars`，其他键抛 `unknown sidecar config key`。生成模板是 `src/Stubs/sidecar.conf.stub.php`。

公共数值（帧大小、超时、inflight、停机等待）来自环境变量，不要写进每一项声明。RocketMQ 是否拉起看 `SIDECAR_ROCKETMQ_ENABLE`，默认关；关着的时候 `SidecarRegistry::get()` 会抛 `sidecar is not registered: rocketmq`，这是配置开关问题，不是连接问题。代理地址、AccessKey、SecretKey 只进 Go 进程环境，不进 PHP 调用日志。

二进制：

| 文件 | 怎么来 |
| --- | --- |
| `Bin/go-sidecar-mac` | 本机 `CGO_ENABLED=0 GOOS=darwin GOARCH=arm64`。不要 UPX |
| `Bin/go-sidecar-linux` | 在容器 `swoolefy-php85-v62` 里交叉编译 `CGO_ENABLED=0 GOOS=linux GOARCH=amd64`，再 `upx -9`。容器自身是 arm64，产物必须是 amd64 |

改了 Go 的请求或响应字段，要重新编译对应平台的二进制并放回 `Bin/`。只改 PHP 不换二进制，线上会解包失败或字段被丢掉。

更细的帧、配置键和消费语义：`src/Core/Sidecar/README.md`、`src/Support/Rocketmq/README.md`、`docs/Swoolefy-Go-Sidecar-UDS(1).md`、`docs/Swoolefy-Go-Sidecar-Framework.md`。

## Support 模块

`src/Support/` 是当前体量最大的部分（300+ 文件）。按能力拆，互不替代进程模型：

| 模块 | 做什么 | 入口 |
| --- | --- | --- |
| `Rocketmq/` | RocketMQ 生产消费，经 UDS sidecar | `RocketmqProducer`、`RocketmqConsumerProcessHandler`、`RocketmqConsumerWorkerProcessHandler` |
| `Kafka/` | rdkafka 生产消费 | `KafkaProducer`、`KafkaConsumerProcessHandler` |
| `Amqp/` | RabbitMQ 生产消费 | `AmqpProducer`、`AmqpConsumerProcessHandler` |
| `Queue/` | Redis List 队列与 ZSet 延迟队列（只有这两套协议） | `ListQueue/ListQueueProducer`、`DelayQueue/DelayQueueProducer` |
| `Job/` | 统一信封 + Handler 注册 + 重试 + 死信，叠在 Redis / AMQP / Kafka 消费进程上，不建表 | `JobEnvelope`、`JobHandlerRegistry`、`JobRunner` |
| `Auth/` | JWT 验签与不可变用户身份 | `JwtAuthGuard`、`AuthUser`（协程上下文用 `FrameworkContext`） |
| `Nacos/` | 配置拉取/监听、服务注册、服务发现 | `NacosFactory`、`NacosRegisterServiceProcess`、`Monitor/ConfigWatcher` |
| `Workflow/` | DAG 编排、HITL、RunStore | `Workflow`、`Engine/WorkflowEngine`、`Definition/WorkflowDefinition` |
| `Neuron/` | LLM Provider、记忆、Embedding 装配 | `NeuronFactory`、`Memory/ChatHistoryFactory` |
| `AI/` | 把 Agent 封装成可编排节点与 SSE 流式输出 | `AI/Node/AINode`、`AI/Stream/SseResponse` |
| `Agent/` | 多 Agent 路由与协程并发调度 | `AgentScheduler`、`AgentRouterInterface` |
| `Rag/` | 文档入库、向量检索、RAG 工具 | `Factory/RagFactory`、`Ingestion/IngestionPipeline`、`RetrievalService` |
| `Mcp/` | MCP Server 配置、工具发现、stdio 保护 | `McpFactory`、`McpProcessRunner` |
| `CapabilityCenter/` | 工具元数据筛选 Top-K 后注入模型 | `CapabilityCenter`、`LazyToolMaterializer` |
| `DocumentOcr/` | DOCX/PDF/图片统一转 Markdown 与分块 | `DocumentOcrFactory`、`Chunking/ChunkingAdapter` |
| `Enum/` `Security/` `HeaderPropagation/` `Kubernetes/` `Tests/` | 枚举基类、出站 SSRF 校验、可信头透传、K8s Job 模板、单测夹具 |

顶层还有几个跨模块类：`ApplicationConfig`（application.yaml + Config）、`FrameworkContext`（Auth + 头传播的协程门面）、`TenantScope`（租户命名空间）、`SupportLog`、`ConsumeRetryLimit`、`ProductionHealthCheck`。

依赖方向：`Workflow → Auth`；`AI` / `Agent` → `Neuron`；`Neuron → CapabilityCenter → Mcp`；`Rag → Neuron`。编排在 Workflow，模型在 Neuron，工具发现在 Mcp，筛选注入在 CapabilityCenter。

## 测试

PHPUnit 11，配置 `phpunit.xml.dist`，bootstrap `PHPUintTest/bootstrap.php`。目录名 `PHPUintTest` 是既有拼写。

| 命令 | 跑什么 |
| --- | --- |
| `composer test` | `run-phpunit.php --testsuite unit,coroutine`，默认 CI 路径 |
| `composer test:unit` / `test:coroutine` | 单套件 |
| `composer test:http` | `Http` + `Unit/Controller`，真服务 + Guzzle |
| `composer test:http:ci` | 额外设 `SWOOLEFY_HTTP_AUTO_START=1`，服务没起会自己拉 |
| `composer test:websocket` | `Websocket`，默认 `127.0.0.1:9508` |
| `composer test:workflow` / `test:support` 等 | 按测试类名过滤的专题集，见 `composer.json` |

`run-phpunit.php` 只做三件事：检查 phpunit 存在、补 `--configuration`、`--list-tests` 预统计后再执行。它**不**设超时、不并行、不限制内存。

默认排除 `@group redis`、`db`、`slow`、`smoke`。不要为了让某一条测试变绿而改排除组。

基类：`PHPUintTest\TestCase`、`CoroutineTestCase`（`runInCoroutine`、`assertCoroutineLeakWithin`）、`Http\HttpIntegrationTestCase`（真服务，默认 9501）、`Websocket\WebsocketSmokeTestCase`。

已知行为，写测试前先看：

- `HttpIntegrationTestCase` 在服务不可达时**无条件** `markTestSkipped`，所以 `SWOOLEFY_HTTP_SKIP_IF_DOWN=0` 并不会让 http 套件失败。要它真跑，用 `SWOOLEFY_HTTP_AUTO_START=1`。
- `phpunit.xml.dist` 的 `<source>` 只含 `src/Support`、`src/Http`、`src/Core`、`src/Websocket`，其余目录不计覆盖率。
- Unix socket 测试在沙箱里可能失败，需在沙箱外重跑。断言 socket 路径时用 `/tmp` 下的短目录。
- `TestReceive` 一类 sidecar 单测若依赖真实代理，不要把本机 broker 地址写死进提交，用假的 group client。

## 本地开发

`Test/` 就是 `create` 生成的应用形状，加上业务样例：

```text
Test/
  入口与全局：App.php Bootstrap.php HttpServer.php Event.php application.yaml nacos.yaml
  Auth/ Common/ Config/ Controller/ Exception/ Logger/ Middleware/ Model/
  Module/<Name>/     业务模块：Controller 之外的 Service、Dto、Workflow，多数带 README
  Pools/ Process/ Protocol/ Resource/ Router/ Scripts/ Skills/ Storage/ Task/ Validation/
  WorkerCron/ WorkerDaemon/
```

启动：`php cli.php start Test`（9501；`cli.php` 的 `APP_META_ARR` 里还登记了 `App` → 9502）。停止 / 重启 / 状态分别是 `stop` / `restart` / `status`。

本地依赖（Redis、MySQL、RabbitMQ、Kafka、RocketMQ namesrv/broker/proxy、Nacos）写在 `dockerfiles/docker-compose.yml`，端口发布到宿主机所有网卡，宿主机和开发容器都能用。PHP 镜像由 `dockerfiles/*.Dockerfile` 构建。`docker-start.sh` 只用于容器内启停 Test；`monitor_reload.sh` 依赖 inotify，仅 Linux；`swaggerui/` 是 ApiDoc 输出目录。

## 改代码时怎么做

1. 先找已有类，再加新类。协议差异放在对应的 `*Server` / Handler，公共行为放 `Core`。
2. 所有文件都带 `declare(strict_types=1);`，新文件照抄。公共方法和关键属性用中文注释说明用途和单位；注释写清约束，不要复述函数名。
3. DTO 继承现有的 `AbstractDto`。`toArray()` 会跳过未初始化的属性。空数组、空 map 不要传给 Go，否则 `DisallowUnknownFields` 或空值校验会拒绝。
4. 错误处理把 warning 转成异常，`@` 压不住。需要忽略「文件不存在」这类警告时，用局部 `set_error_handler` 包住那一次调用，并在 `finally` 里 `restore_error_handler`。`SocketEndpoint` 里已有这个写法。
5. 改 `EventCtrl::init()` 的顺序要同时看 Daemon、Cron、Script、HTTP。Sidecar 必须在 `onStartRegisterProcess()` 之前 `attach()`，否则业务进程比 Go 进程更早启动。
6. 改 shutdown 时看三处：`SidecarSupervisor::bindShutdownHook()`、`EventCtrl::managerStop()`、MQTT 与 WebSocket 的 `*ShutdownCoordinator`。不要只改其中一处。
7. 生成文件的行为改 `src/Stubs/`。`CreateCmd` 和 `BaseCmd` 都是读 stub 再替换命名空间，`protocolMap`（`BaseCmd:76`）决定协议对应的 Server 类名。
8. 改了行为就顺手更新对应 README 或 `docs/`；本文件的事实若与代码不符，一并修正。
9. 提交信息用中文，说明为什么改。用户没有明确要求时不要提交，也不要推送。

## 不要做的事

- 不要把 `sidecar_runtime_dir` 从 `APP_PATH` 改掉，除非用户明确要求。
- 不要给 mac 二进制做 UPX。压缩后的 Mach-O 不能执行。
- 不要新增第二套 Redis list / zset 队列协议。延迟队列和 list 队列沿用 `src/Support/Queue/`。
- 不要新增 `SidecarMqtt5Spec` 这类平行 Spec，也不要改 Go 的 `pluginsFrom`。插件字段加在已有的 Spec 拆分上。
- 不要在 `onInit()` 里注册消费进程，统一放 `onStartRegisterProcess()`。
- 不要为了「扫干净残留 sock」在启动时删除整个 `sidecars_sock` 或别的服务目录。
- 不要把 RocketMQ 的 `$consumerTag` 改回字符串，也不要在协议里同时保留 `key` 和 `keys`。
- 不要用 `pkill -f go-sidecar` 这种长匹配去杀进程，那会误杀用户正在跑的 sidecar。要停就按记录下来的 pid。
- 不要用 `isCliService()` 判断「是不是协议服务」，它对 console 命令同样为真。
- 不要在文档或回复里粘贴 AccessKey、SecretKey、`.env` 全文。

## 已知缺口

- `src/Stubs/RpcServer.stub.php` 不存在，但 `BaseCmd::$protocolMap` 把 RPC 映射到 `RpcServer`，`php cli.php create --protocol=rpc` 会在写 Server 文件时读不到模板。
- `HttpIntegrationTestCase` 无条件 skip，CI 里 `SWOOLEFY_HTTP_SKIP_IF_DOWN=0` 目前不会让 http 套件失败。

## 先读哪里

| 要改的东西 | 先打开 |
| --- | --- |
| 入口、命令、生命周期 | `swoolefy`、`cli.php`、`src/Cmd/BaseCmd.php`、`src/Cmd/Command/StartCmd.php` |
| 启动顺序、自定义进程 | `src/Core/EventCtrl.php`、`src/Core/BaseServer.php`、`Test/Event.php` |
| HTTP 路由与中间件 | `src/Http/HttpRoute.php`、`src/Http/Middleware/`、`Test/Router/` |
| WebSocket | `src/Websocket/README.md`、`WebsocketEventServer.php`、`Websocket/Cluster/` |
| Daemon / Cron | `src/Worker/`、`src/Worker/Cron/README.md`、`src/Worker/Runtime/CliControlServer.php`、`Test/WorkerDaemon/`、`Test/WorkerCron/` |
| Sidecar 进程与路径 | `src/Core/Sidecar/README.md`、`SocketEndpoint.php`、`SidecarSupervisor.php` |
| RocketMQ 业务 | `src/Support/Rocketmq/README.md`、`../swoolefy-go-sidecar/internal/rocketmq/` |
| Job / 队列 | `docs/Job.md`、`src/Support/Job/README.md`、`src/Support/Queue/README.md` |
| AI 与工作流 | `docs/AI-WORKFLOW.md`、`src/Support/Workflow/README.md`、`src/Support/Neuron/README.md` |
| 配置与 Nacos | `src/Support/ApplicationConfig.php`、`Test/application.yaml`、`src/Support/Nacos/README.md` |
| 测试怎么跑 | `docs/PHPUnitTest.md`、`phpunit.xml.dist`、`PHPUintTest/run-phpunit.php` |
| 命令与创建应用 | `src/Cmd/CreateCmd.php`、`src/Stubs/`、`docs/Cmd.md`（FIFO 部分已被 UDS 方案取代） |
