# AGENTS.md

这份文件给在本仓库里改代码的代理用。先看这里，再下钻对应 README。不要把本文件当成 API 手册；细节以源码和 `docs/`、各模块 `README.md` 为准。

## 仓库是什么

swoolefy 是基于 Swoole 的常驻内存协程框架。当前主线是 **6.2**（分支 `swoolefy-6.2-x`）。

- PHP `^8.4`，Swoole `>= 6.1`
- 命名空间 `Swoolefy\` 映射到 `src/`（Composer PSR-4）
- 协议服务：HTTP、WebSocket、TCP（RPC）、UDP、MQTT
- Worker 服务：Daemon 常驻、Cron 调度、Script 一次性脚本
- 业务样例在 `Test/`，命名空间 `Test\`，不进 Composer PSR-4，由 `Test/Autoloader.php` 按目录加载

两个经常一起改的独立仓库，和本仓库同级：

| 仓库 | 作用 |
| --- | --- |
| `../library`（`bingcool/library`，分支 `library-6.x`） | DB、Redis、Queue、Uuid、Oauth 等协程组件。框架通过 Composer 依赖它 |
| `../swoolefy-go-sidecar`（分支 `main`） | Go sidecar。编译产物放进 `src/Core/Sidecar/Bin/`，PHP 与二进制必须一起发 |

不要在一次改动里偷偷改 library 的行为却只改 swoolefy。跨仓库的协议字段（sidecar JSON、Uuid Lua）要两边同时看。

## 目录

```text
swoolefy                 Symfony Console：start / stop / restart / reload / status / create
cli.php daemon.php       协议服务、Daemon、Cron、Script 的进程入口
cron.php script.php
src/                     框架。Swoolefy\
  Core/                  服务器、事件、路由分发、协程、组件、Sidecar
  Http/ Websocket/       各协议服务器与 Handler
  Tcp/ Udp/ Mqtt/ Rpc/
  Worker/                Daemon / Cron / Script 的 MainManager 与 Worker 进程
  Cmd/                   swoolefy 命令实现，CreateCmd 从 Stubs 生成应用
  Stubs/                 create 时复制的模板
  Support/               业务能力：RocketMQ、Kafka、AMQP、Job、Auth、Workflow、AI…
  Exception/
Test/                    可运行的样例应用（APP_NAME=Test）
PHPUintTest/             PHPUnit 11。目录名就是这个拼写，不要改成 PHPUnit
docs/                    方案与模块说明
dockerfiles/             本地 PHP 镜像。Linux sidecar 在容器 swoolefy-php85-v62 里编译
```

`src/Support/` 按能力拆开，互不替代进程模型：

| 目录 | 做什么 |
| --- | --- |
| `Rocketmq/` `Kafka/` `Amqp/` `Queue/` | 生产与消费。消费是自定义进程或 Daemon Worker，不是框架替你起的线程池 |
| `Job/` | 统一信封、Handler、Registry、重试。叠在现有 Redis / AMQP / Kafka 消费进程上，不建表，不替换 `ProcessManager` |
| `Auth/` | JWT 与协程身份。HTTP、WebSocket、Workflow HITL 走同一套 |
| `Nacos/` | 配置监听、注册与发现 |
| `Workflow/` `Neuron/` `AI/` `Agent/` `Rag/` `Mcp/` `DocumentOcr/` `CapabilityCenter/` | AI 与工作流。编排在 Workflow，模型在 Neuron，工具发现在 CapabilityCenter |

应用样例 `Test/` 的形状就是 `create` 生成的应用形状：

```text
Test/
  Event.php              事件实现，继承 EventHandler
  Protocol/conf.php      协议配置，event_handler 指向本应用的 Event
  Config/                sidecar.php、组件、.env
  Router/                HTTP 路由
  Controller/ Middleware/ Model/
  Module/<Name>/         按业务模块放 Controller 之外的 Service、Dto、Workflow
  Process/               协议服务里的自定义进程
  WorkerDaemon/          daemon.php 的进程声明与处理类
  WorkerCron/            cron.php 的调度声明
```

## 服务类型

`swoolefy` 脚本在进程入口定义四个全局函数，`SystemEnv` 再包一层。判断服务类型用这些函数，不要自己猜进程名。

| 函数 | 何时为真 |
| --- | --- |
| `isDaemonService()` | `daemon.php`，常量 `IS_DAEMON_SERVICE` |
| `isCronService()` | `cron.php`，常量 `IS_CRON_SERVICE` |
| `isScriptService()` | `script.php`，常量 `IS_SCRIPT_SERVICE` |
| `isWorkerService()` | 上面三个任一为真 |
| `isCliService()` | 不是 Worker 服务，即 HTTP / WebSocket / TCP / UDP / MQTT |

它们互相排斥。Sidecar 的 socket 子目录按这个优先级取名：`cron`、`script`、`daemon`，否则 `cli`。

k8s Job 跑 script 时会带 `--fromk8s`。`SystemEnv::isFromK8s()` 为真时，script 不打印启动横幅和进程表，避免撑大执行记录。

## 启动与事件

协议服务的构造函数里调用 `EventCtrl::init()`，发生在 `Server::start()` 之前，跑在 Master，没有协程。顺序固定：

1. Script 服务解析要跑的类。
2. 注册日志组件。Daemon / Cron 走 Worker 的日志注册，其余走 `SystemEnv`。
3. 非 Worker 服务加载路由。
4. `onInit()`。应用在这里做慢 SQL、租户拦截器这类全局注册。不要在这里 `addProcess`。
5. 框架自己的 user process：系统采集、热重载、Nacos 注册、WebSocket 集群推送。仅非 Worker 服务。
6. Worker 服务改为 `onWorkerServiceInit()`，再引导 `WORKER_SERVICE_NAME` 与 `PROCESS_CLASS`。
7. `SidecarSupervisor::attach()`：登记 sidecar、按需拉起 Go 进程。
8. 给 Server 绑定 `BeforeShutdown`，退出时删本进程的 sidecar sock。
9. `onStartRegisterProcess()`。协议服务的自定义进程写在这里。
10. `printStartInfo()`。

应用类是 `Event extends EventHandler`，`EventHandler extends EventCtrl`。`create` 从 `src/Stubs/event_handle.stub.php` 生成 `Event.php`，里面有空的 `onStartRegisterProcess()`，函数体是 `// todo`。

自定义进程用 `ProcessManager::getInstance()->addProcess(名字, 类名, ...)`。同一个消费组加 Topic 只登记一个拉取进程。`addProcess` 发生在 `start()` 之前，这里拿不到 pid。

Worker 服务不走 `onStartRegisterProcess()` 里的协议进程表。Daemon 进程写在 `WorkerDaemon/worker_daemon_conf.php`，按 `group_1` 这类分组 `include conf/*.php`。Cron 写在 `WorkerCron/`。字段至少要有 `process_name`、`handler`、`worker_num`；`max_handle`、`life_time`、`limit_run_coroutine_num` 有默认值。

HTTP 请求在 Worker 里：`onRequest` → `App` 构造并 `run()` → 路由 → 前置中间件 → Controller → 后置中间件 → `App::end()` 归还连接池。协程里用 `goApp()` 隔离 DB / Redis / Curl。限制并发用 `Parallel`，等待一组协程用 `GoWaitGroup`。

组件是协程单例。请求结束必须归还池子。不要把连接、`SidecarClient`、可写的 DTO 存进跨协程的静态属性。

## Sidecar 与 RocketMQ

PHP 不连 RocketMQ。Master 在 `attach()` 里 `addProcess` 一个 Go 进程，`exec` 替换成 `Bin/go-sidecar-*`。Worker 和消费进程通过 Unix socket 发帧：4 字节大端长度加一个 JSON 对象。

硬约束：

- 一项 `SidecarSpec` 等于一个操作系统进程，等于一个 socket。不要让两个声明共用一个 socket。
- `sidecar_socket_path` 只能是目录，不能是 `.sock` 文件。空字符串用 `{sidecar_runtime_dir}/sidecars_sock`。框架再追加服务子目录，业务不要自己再写一层 `cli`，否则会变成 `cli/cli`。
- 文件名由框架生成：`{APP_NAME}-{Ymd}-{His}-{port}-{name}.sock`。`Ymd-His` 每个进程只取一次。
- macOS 路径上限 103 字节，Linux 107 字节，都不含结尾 NUL。测试用短的 `/tmp/...`，不要用 `sys_get_temp_dir()`（macOS 上那条路径会超限）。
- `sidecar_clean_sockets_on_start` 默认 `false`。只有显式打开时，才删除当前服务目录里没人监听的 `.sock`。目录、普通文件、符号链接、别的服务目录不动。
- 本进程 shutdown 时只 `unlink` 自己登记过的 sock。失败不抛错，文件留下。`BeforeShutdown` 在 Master；`ManagerStop` 再删一次。WebSocket、MQTT 若覆盖 `BeforeShutdown`，回调开头已经调用 `unlinkOwnSockets()`，不要把这两处调用删掉。
- 不要用未加 Master pid 判断的 `register_shutdown_function` 去删 sock。`start()` 之前注册的 shutdown 函数会被 fork 进 Worker，Worker 退出时会删掉还在用的 socket。
- `call()` 必须在协程里。字节写出之前失败可以换连接重试；写出之后失败是 `OutcomeUnknownException`，`rocketmq.producer.send` 不能自动再发一次。
- Go 用 `DisallowUnknownFields` 解请求。PHP 字段名和二进制必须一起发布。协议里是 `keys`，不是 `key`。
- `$consumerTag` 在 PHP 基类上是 `array`，例如 `['paid', 'cancel']`。发给 Go 之前转成 `paid || cancel`。含 `*` 或空数组表示全部。不要把子类改回 `string`。
- `$consumerSql` 与具体 Tag 互斥。SQL92 需要 broker `enablePropertyFilter=true`。过滤在 broker 上做，被滤掉的消息对该消费组算已消费。
- 同一个消费组加 Topic 同时只能有一个进程在 `receive`。第二个会立刻得到 `OVERLOADED`。协议服务用 `RocketmqConsumerProcessHandler`，Daemon / Cron 常驻用 `RocketmqConsumerWorkerProcessHandler`，`worker_num` 保持 1。两条路径不要订阅同一个消费组加 Topic。
- SimpleConsumer 每次 `Receive` 只挂一个队列。`awaitDuration` 当前是 `5 * time.Second`（`swoolefy-go-sidecar/internal/rocketmq/sdk_consumer.go`）。加上 SDK 默认 3 秒请求超时后，Proxy 算出的长轮询大约 6.5 秒，并且不会短于 5 秒（`grpcClientConsumerMinLongPollingTimeoutMillis`）。再缩短客户端截止时间会让 Proxy 返回 `BAD_REQUEST`。不要在一次 PHP `receive` 里循环多次 `Receive` 去扫队列，那会把几轮 5 秒加总成二十多秒。
- 选中的队列在拉取开始时已经有消息，broker 会马上返回。队列是空的，就等到这一轮长轮询结束。
- 消费确认失败只记日志。未确认的消息在 `invisibleMs` 之后重投。`changeInvisible` 之后的 ack 在 RocketMQ 5.3.x proxy 上可能不生效（apache/rocketmq#9583）。长任务优先把 `$invisibleMs` 设够，业务按 `messageId` 幂等。

框架自带的 sidecar 声明在 `src/Core/Sidecar/Config/SystemSidecar.php`。应用追加写 `Config/sidecar.php`，顶层只允许 `sidecar_runtime_dir`、`sidecar_clean_sockets_on_start`、`sidecars`。生成模板是 `src/Stubs/sidecar.conf.stub.php`。

公共数值（帧大小、超时、inflight、停机等待）来自环境变量，不要写进每一项声明。RocketMQ 是否拉起看 `SIDECAR_ROCKETMQ_ENABLE`，默认关。代理地址、AccessKey、SecretKey 只进 Go 进程环境，不进 PHP 调用日志。

二进制：

| 文件 | 怎么来 |
| --- | --- |
| `Bin/go-sidecar-mac` | 本机 `CGO_ENABLED=0 GOOS=darwin GOARCH=arm64`。不要 UPX |
| `Bin/go-sidecar-linux` | 在容器 `swoolefy-php85-v62` 里交叉编译 `CGO_ENABLED=0 GOOS=linux GOARCH=amd64`，再 `upx -9`。容器挂载是 `/home/wwwroot` ← 宿主机 `Documents/wwwphp`。容器自身是 arm64，产物必须是 amd64 |

改了 Go 的请求或响应字段，要重新编译对应平台的二进制并放回 `Bin/`。只改 PHP 不换二进制，线上会解包失败或字段被丢掉。

更细的帧、配置键和消费语义：

- `src/Core/Sidecar/README.md`
- `src/Support/Rocketmq/README.md`
- `docs/Swoolefy-Go-Sidecar-UDS(1).md`
- `docs/Swoolefy-Go-Sidecar-Framework.md`

## 配置与环境

- 协议配置在应用的 `Protocol/conf.php`。`event_handler` 指向应用自己的 `Event::class`。
- `.env` 由 `SystemEnv` 读进 Dotenv，不会自动进入子进程。sidecar 要的变量由 `attach()` 在 `exec` 前 `putenv`。
- 环境名：`dev` / `test` / `gra` / `prd`，常量 `SWOOLEFY_ENV`。
- 不要提交 `Test/.env`、密钥、`my.txt`。读 `.env` 时只查需要的那一行，不要把文件内容打出来。
- `Storage/` 在 `.gitignore` 里。日志、上传文件不要加进提交。

## 测试

PHPUnit 11，配置 `phpunit.xml.dist`，bootstrap `PHPUintTest/bootstrap.php`。目录名 `PHPUintTest` 是既有拼写。

| 命令 | 跑什么 |
| --- | --- |
| `composer test` | `unit` + `coroutine`。默认 CI 路径 |
| `composer test:unit` | 只跑 unit |
| `composer test:coroutine` | 协程套件，基类 `CoroutineTestCase` |
| `composer test:http` | HTTP curl。服务没起来会跳过 |
| `composer test:websocket` | WebSocket |

默认排除 `@group redis`、`db`、`slow`、`smoke`。不要为了让某一条测试变绿而改 `phpunit.xml.dist` 的排除组。

Unix socket 测试在沙箱里可能失败，需要在沙箱外重跑。断言 socket 路径时用 `/tmp` 下的短目录。

`TestReceive` 一类 sidecar 单测若依赖真实代理，不要把本机 broker 地址写死进提交。单测用假的 group client。

## 改代码时怎么做

1. 先找已有类，再加新类。协议差异放在对应的 `*Server` / Handler，公共行为放 `Core`。
2. 新的框架文件使用 `declare(strict_types=1);`，公共方法和关键属性用中文注释说明用途和单位。注释写清约束，不要复述函数名。
3. DTO 继承现有的 `AbstractDto`。`toArray()` 会跳过未初始化的属性。空数组、空 map 不要传给 Go，否则 `DisallowUnknownFields` 或空值校验会拒绝。
4. 错误处理把 warning 转成异常，`@` 压不住。需要忽略「文件不存在」这类警告时，用局部 `set_error_handler` 包住那一次调用，并在 `finally` 里 `restore_error_handler`。`SocketEndpoint` 里已有这个写法。
5. 改 `EventCtrl::init()` 的顺序要同时看 Daemon、Cron、Script、HTTP。Sidecar 必须在 `onStartRegisterProcess()` 之前 `attach()`，否则业务进程比 Go 进程更早启动。
6. 改 shutdown 时看三处：`SidecarSupervisor::bindShutdownHook()`、`EventCtrl::managerStop()`、MQTT 与 WebSocket 的 `*ShutdownCoordinator`。不要只改其中一处。
7. 生成文件的行为改 `src/Stubs/`。`CreateCmd` 和 `BaseCmd` 都是读 stub 再替换命名空间。
8. 提交信息用中文，说明为什么改。不要把 `Test/.env`、二进制之外的构建缓存、`my.txt` 加进去。用户没有明确要求时不要提交，也不要推送。

## 不要做的事

- 不要把 `sidecar_runtime_dir` 从 `APP_PATH` 改掉，除非用户明确要求。
- 不要给 mac 二进制做 UPX。压缩后的 Mach-O 不能执行。
- 不要新增第二套 Redis list / zset 队列协议。延迟队列和 list 队列沿用 `src/Support/Queue/`。
- 不要新增 `SidecarMqtt5Spec` 这类平行 Spec，也不要改 Go 的 `pluginsFrom`。插件字段加在已有的 Spec 拆分上。
- 不要在 `onInit()` 里注册消费进程。
- 不要为了「扫干净残留 sock」在启动时删除整个 `sidecars_sock` 或别的服务目录。
- 不要把 RocketMQ 的 `$consumerTag` 改回字符串，也不要在协议里同时保留 `key` 和 `keys`。
- 不要用 `pkill -f go-sidecar` 这种长匹配去杀进程，那会误杀用户正在跑的 sidecar。要停就按记录下来的 pid。
- 不要在文档或回复里粘贴 AccessKey、SecretKey、`.env` 全文。

## 先读哪里

| 要改的东西 | 先打开 |
| --- | --- |
| 启动顺序、自定义进程 | `src/Core/EventCtrl.php`、`Test/Event.php`、`src/Stubs/event_handle.stub.php` |
| HTTP 路由与中间件 | `src/Http/`、`Test/Router/`、`Test/Middleware/` |
| Daemon / Cron | `src/Worker/`、`Test/WorkerDaemon/`、`Test/WorkerCron/`、`src/Worker/Cron/README.md`、`src/Worker/Runtime/CliControlServer.php` |
| Sidecar 进程与路径 | `src/Core/Sidecar/README.md`、`SocketEndpoint.php`、`SidecarSupervisor.php` |
| RocketMQ 业务 | `src/Support/Rocketmq/README.md`、`../swoolefy-go-sidecar/internal/rocketmq/` |
| Job | `docs/Job.md`、`src/Support/Job/README.md` |
| 测试怎么跑 | `docs/PHPUnitTest.md`、`phpunit.xml.dist` |
| 命令与创建应用 | `docs/Cmd.md`、`src/Cmd/CreateCmd.php` |
