# Sidecar

PHP 不直接连 RocketMQ。Swoole 启动时拉起一个本机 Go 进程，Worker 和自定义进程通过 Unix socket 发 JSON 帧调用它。PHP 侧只负责进程、路径、帧和调用；RocketMQ 的发送、拉取、确认在 Go 插件里完成。

Go 工程是独立模块 `swoolefy-go-sidecar`。编译产物放在本目录的 `Bin/`。

## 目录

| 路径 | 作用 |
| --- | --- |
| `Config/SystemSidecar.php` | 框架自带的 `rocketmq` 进程声明 |
| `Bin/go-sidecar-mac` | macOS 可执行文件，当前按 `darwin/arm64` 编译 |
| `Bin/go-sidecar-linux` | Linux 可执行文件，当前按 `linux/amd64` 编译，构建时可用 UPX 压缩 |
| `SidecarConfig.php` | 合并框架声明和应用 `Config/sidecar.php`，并填入公共数值 |
| `SidecarSpec.php` | 校验一项声明，算出 socket 路径，编成 `SIDECAR_*` 环境变量 |
| `SidecarSupervisor.php` | 在 `EventCtrl::init()` 末尾 `addProcess`，再 `exec` 二进制 |
| `SocketEndpoint.php` | 生成路径，创建 `0700` 目录，只删除无人监听的 socket 残留 |
| `SidecarRegistry.php` | 启动前登记已启用的 `SidecarSpec`。Worker 通过 fork 继承这份登记 |
| `SidecarClient.php` | 协程里一次调用一条连接 |
| `FrameCodec.php` | 4 字节大端长度 + 一个 JSON 对象 |
| `SidecarLog.php` | 调用日志，只记元数据 |
| `src/Support/Rocketmq/` | 生产、消费和 DTO。Core 不解析这些业务方法 |

应用追加的进程写在 `APP_PATH/Config/sidecar.php`。`create` 时从 `src/Stubs/sidecar.conf.stub.php` 复制，里面有 `'sidecar_clean_sockets_on_start' => false`。不写 `sidecars` 表示不追加进程。

## 原理

一个 Sidecar 声明对应一个操作系统进程，也对应一个 Unix socket。进程之间不共享连接。PHP 每次 `call()` 新建 `Swoole\Coroutine\Client`，写完一帧、读完一帧就关掉。不要把同一个 `Client` 交给多个协程。

`call()` 必须跑在协程里。`Coroutine::getCid()` 不大于 0 时直接抛 `SidecarException`。

调用分两段：

1. 字节还没写出。连接失败、编码失败、超时都抛 `SidecarException`。发送可以换一条新连接再试。
2. 第一个字节已经写出。之后的短写、短读、超时、`requestId` 不一致，抛 `OutcomeUnknownException`。对端可能已经执行。`rocketmq.producer.send` 遇到这个异常不能自动再发一次。

`call()` 本身不因为业务 code 抛异常。返回的 `SidecarResponseDto` 里带着 `code`。要失败即抛，再调用 `assertOk()`。`RocketmqProducer::send()` 和 `health()` 内部已经 `assertOk()`。

Go 进程宕了或代理暂时不可达时，进程保持运行，对该次调用返回 `UNAVAILABLE`，并自行退避后重连。PHP 不负责把 Go 进程重新拉起来；Swoole 会在这个 user process 退出后重启它。

同一条连接上没有多路复用。并发来自多个协程各自建连接。Go 侧同时处理的请求数受 `sidecar_max_inflight` 限制，超出返回 `OVERLOADED`。

## 启动流程

协议 `conf.stub.php` 不展开 sidecar。`SidecarSupervisor::attach()` 在 Server 配置里没有 `sidecars`、`sidecar_runtime_dir` 时，直接读 `SidecarConfig::fromApp()`。应用自己的 `Protocol/conf.php` 如果已经展开了这两项，就用配置里的那一份。

`EventCtrl::init()` 的顺序是：

1. `static::onInit()`。应用在这里用 `ProcessManager` 注册自己的进程，包括 RocketMQ 消费进程。
2. `SidecarSupervisor::attach()`。读取 `sidecars`，跳过 `sidecar_enable` 为假的项，校验启用项，`SocketEndpoint::prepare()`，然后 `addProcess`。
3. `printStartInfo()`。此时 Go 进程尚未 `listen`。

`addProcess()` 的返回值 `0` 是成功，只有 `false` 是失败。Worker id 为 0 不能当成注册失败。

`attach()` 登记的回调里先 `putenv` 全部非空 `SIDECAR_*`。插件列表包含 `rocketmq` 且当前没有 `rocketmq.client.logRoot` 时，再把它设为 `(LOG_PATH 或 APP_PATH/Storage/Logs)/rocketmq`，避免 SDK 去创建 `/logs`。然后 `Process::exec()` 换成 Go 二进制。

Go 启动顺序：

1. 读取 `SIDECAR_*`。缺 `SIDECAR_SOCKET_PATH` 时进程以状态码 1 退出，标准错误是 `sidecar config: SIDECAR_SOCKET_PATH`。
2. 按 `SIDECAR_PLUGINS` 加载插件。当前只认识 `rocketmq`。
3. 注册 `sidecar.version`、`health.check`，再注册插件方法。
4. 创建 socket 目录（`0700`），`listen` 时用 `umask 0077`，socket 文件权限是 `0600`。
5. `listenerOk` 置为真，插件 `Start()`。RocketMQ Producer 在后台连代理；Consumer 等到第一次 `receive` 才按订阅启动。
6. 接受连接。收到 `SIGTERM` 或 `SIGINT` 后停止接入，取消上下文，等待在途请求最多 `sidecar_shutdown_grace_ms`，再停插件。

Server 启动后，业务自定义进程和 sidecar 进程一起被 fork。业务进程的 `run()` 可能先于 Go 的 `listen` 执行。这时 Unix socket 文件还不存在，`connect` 立刻失败，异常信息是 `connect failed: rocketmq`。它不会把调用超时耗完。下一轮循环再连时，socket 通常已经在监听。`health()` 如果只在 `run()` 开头打一次，这次失败不会自动再查。

替换 `Bin/` 里的二进制后要重启 Swoole。正在跑的进程持有旧文件，新文件要等该 user process 重新 `exec` 才会加载。

## 帧

请求和响应都是：

```text
4 字节大端无符号长度 + UTF-8 JSON 对象
```

长度是 JSON 正文的字节数，不含这 4 字节。正文至少 1 字节，至多 `sidecar_max_frame_bytes`（默认 16 MiB，上限也是 16 MiB）。

请求 JSON：

```json
{
  "requestId": "32位十六进制",
  "method": "rocketmq.producer.send",
  "payload": {},
  "timeoutMs": 3000
}
```

`requestId` 由 `SidecarClient` 生成。`payload` 必须是纯数组：字符串、数字、布尔、null、数组。嵌套 DTO 先 `toArray()`。对象会直接拒绝。

响应 JSON：

```json
{
  "requestId": "与请求相同",
  "code": "OK",
  "message": "",
  "payload": {}
}
```

`Swoole\Coroutine\Client::recv()` 的参数是超时秒数，一次可能返回整帧甚至超过请求长度的数据。`FrameCodec` 把多出来的字节留在缓冲区里，先切出 4 字节头，再按长度切正文。

## 返回码

| code | 含义 |
| --- | --- |
| `OK` | 成功。`payload` 是该方法约定的字段 |
| `INVALID_ARGUMENT` | 参数不合法，例如 topic、消费组、帧字段 |
| `METHOD_NOT_FOUND` | 方法未注册 |
| `UNAVAILABLE` | 进程还不能完成这次调用，或上游暂时不可用。`message` 是具体原因 |
| `OVERLOADED` | 在途请求达到 `sidecar_max_inflight`，或同一个消费组 + Topic 已经有一次 `receive` 未结束 |
| `TIMEOUT` | 在声明的超时内没有完成 |
| `INTERNAL` | 插件内部不能完成，且不属于上面几类 |
| `OUTCOME_UNKNOWN` | 结果无法判断。PHP 在字节已写出后自己也会用这个 code 记日志 |

PHP 日志按 code 分级：`OK` 为 info；`INVALID_ARGUMENT`、`METHOD_NOT_FOUND`、`OVERLOADED` 为 warning；其余为 error。

## 配置

### 合并规则

`SidecarConfig::fromApp()` 先读 `Config/SystemSidecar.php`，再读 `APP_PATH/Config/sidecar.php`。

- 应用文件不存在，或返回 `[]`：只用框架声明。
- 应用返回列表：整份列表当作额外的 `sidecars`。
- 应用返回关联数组：只允许 `sidecar_runtime_dir`、`sidecar_clean_sockets_on_start`、`sidecars`。其他键抛 `unknown sidecar config key`。
- `sidecar_clean_sockets_on_start` 默认 `false`。应用写了才覆盖框架的值，没写就沿用框架的。
- 两边的 `sidecars` 按框架在前、应用在后拼接。
- 应用的 `sidecar_runtime_dir` 非空时覆盖框架的值。空字符串不覆盖。
- 单项没写的五个公共数值，用 `.env` 填上。

公共数值和范围：

| 配置键 | 环境变量 | 默认 | 范围 |
| --- | --- | --- | --- |
| `sidecar_max_frame_bytes` | `SIDECAR_MAX_FRAME_BYTES` | 16777216 | 1024–16777216 |
| `sidecar_default_timeout_ms` | `SIDECAR_DEFAULT_TIMEOUT_MS` | 3000 | 1–`sidecar_max_timeout_ms` |
| `sidecar_max_timeout_ms` | `SIDECAR_MAX_TIMEOUT_MS` | 30000 | 1–300000 |
| `sidecar_max_inflight` | `SIDECAR_MAX_INFLIGHT` | 128 | 1–1024 |
| `sidecar_shutdown_grace_ms` | `SIDECAR_SHUTDOWN_GRACE_MS` | 5000 | 0–30000 |

`call($name, $method, $payload, $timeoutMs)` 的 `$timeoutMs <= 0` 时用该项的默认超时，大于最大超时则截到最大超时。

### 框架自带的 rocketmq

`SystemSidecar.php` 当前内容等价于：

- `sidecar_runtime_dir` 为 `APP_PATH`。
- 名称 `rocketmq`。
- `sidecar_enable` 来自 `SIDECAR_ROCKETMQ_ENABLE`，缺省关闭。未设置、`false`、`0`、`off`、`no` 都跳过该项，并且不检查二进制是否存在。设为 `true` 才拉起。无法识别的值（例如整数 `2`）启动失败。
- 二进制按 `PHP_OS_FAMILY` 选择：`Darwin` 用 `Bin/go-sidecar-mac`，其他用 `Bin/go-sidecar-linux`。
- `sidecar_socket_path` 为空，目录用 `{sidecar_runtime_dir}/sidecars_sock`。这个键只能写目录，不能写 `.sock` 文件。
- `sidecar_plugins` 为 `rocketmq`。
- 代理地址、AccessKey、SecretKey 来自 `.env`。允许的 Topic 为 `*`。
- 消费进程不在这里登记。应用在 `onInit()` 里 `addProcess` 自己的进程类。

`.env`：

```env
SIDECAR_ROCKETMQ_ENABLE=false
SIDECAR_ROCKETMQ_PROXY_ENDPOINT=127.0.0.1:8081
SIDECAR_ROCKETMQ_ACCESS_KEY=
SIDECAR_ROCKETMQ_SECRET_KEY=
```

`SIDECAR_ROCKETMQ_PROXY_ENDPOINT` 只接受 `host:port`。不要写 `http://`，也不要带路径。端口 1–65535。AccessKey 和 SecretKey 可以为空；空字符串不会 `putenv`。这两个值只进入 Go 进程环境，不进 PHP 调用日志。

### 默认 socket 路径

`sidecar_socket_path` 只填目录。空字符串时目录是 `{sidecar_runtime_dir}/sidecars_sock`，写了绝对路径就用那个目录。两种情况都会再加一层服务目录，避免同一份代码同时跑多个服务时，重启清理删掉别人的 sock：

| 当前进程 | 子目录 |
| --- | --- |
| `isCronService()` | `cron` |
| `isScriptService()` | `script` |
| `isDaemonService()` | `daemon` |
| 都不是（HTTP、WebSocket 等） | `cli` |

例如 `sidecar_socket_path` 为 `/data/socks`、当前是协议服务时，目录是 `/data/socks/cli`。文件名一律生成，不接受指定：

```text
{目录}/{服务}/{APP_NAME}-{Ymd}-{His}-{port}-{sidecar_name}.sock
```

例如 `Test-20261015-120412-32885-rocketmq.sock`。`Ymd-His` 是本次进程启动时刻，一次启动只取一次，多个 sidecar 共用。`APP_NAME` 是常量 `APP_NAME`。`port` 是当前协议配置里的 `port`。应用名缺失或端口不大于 0，抛 `cannot build sidecar socket path without app name and port`。

框架把 `sidecar_runtime_dir` 设成了 `APP_PATH`，所以默认文件是：

```text
{APP_PATH}/sidecars_sock/cli/{APP_NAME}-{Ymd}-{His}-{port}-rocketmq.sock
```

`sidecar_clean_sockets_on_start` 默认 `false`：启动时不扫目录，旧的 `.sock` 留着。设为 `true` 时，每次 `start` / `restart` 进入 `attach()`、拉起 sidecar 之前，只删当前服务目录里没人监听的 `.sock` 文件，例如 `sidecars_sock/cli` 里连不上的残留。目录本身不删。200 毫秒内能连上的 sock 视为正在使用，留下，所以 `script` 目录里其他正在跑的脚本不受影响。普通文件、子目录、符号链接不动。其他服务目录不扫。服务目录本身如果是符号链接，或者目录名不是 `cli`、`cron`、`script`、`daemon`，直接拒绝。写成 `.sock` 文件的配置直接启动失败。

服务 shutdown 时删除本进程登记过的 sock。`BeforeShutdown` 和 `ManagerStop` 都会做这一次；WebSocket、MQTT 自己的关机回调也会先删。`unlink` 失败不抛错，文件留在目录里。

路径超过系统上限（macOS 103 字节、Linux 107 字节，都不含结尾的 NUL）时启动失败，信息是 `sidecar socket path exceeds N bytes`。

如果运行目录也是空字符串，`SidecarSpec` 再用 `APP_PATH/runtime` 作为目录。显式写了绝对路径时，用该路径，不再拼接。

目录模式 `0700`。已存在的路径如果是符号链接、普通文件或目录，启动失败。是 socket 且 200ms 内能连上，说明已有进程在监听，启动失败，不会把别人的 socket 删掉。是 socket 但连不上，当成残留删除，再由 Go `listen` 创建。首次启动文件不存在是正常情况。框架的错误处理会把 warning 变成异常，所以这里不用 `@` 去压 `lstat`。

### 一项声明的字段

启用项里未知的 `sidecar_*` 键会失败。名称必须匹配 `^[a-z0-9][a-z0-9_-]{0,31}$`。同名进程不能登记两次。

| 键 | 要求 |
| --- | --- |
| `sidecar_name` | 上列正则 |
| `sidecar_enable` | 布尔语义。未写时 `applyDefaults()` 视为关闭 |
| `sidecar_exec_binary` | 绝对路径，且是可执行文件 |
| `sidecar_args` | 字符串列表，元素不能含 NUL |
| `sidecar_socket_path` | 空字符串或绝对目录。不能是 `.sock` 文件，文件名由框架生成 |
| `sidecar_plugins` | 一个插件名，`^[a-z0-9]+$`。必须已在 `SidecarPluginRegistry` 登记 |
| `sidecar_rocketmq_proxy_endpoint` | 仅 `rocketmq` 项。必须是 `host:port` |
| `sidecar_rocketmq_access_key` | 字符串 |
| `sidecar_rocketmq_secret_key` | 字符串 |
| `sidecar_rocketmq_allowed_topics` | `*`、空字符串，或逗号分隔的 Topic。不能在列表里再写 `*` |

这些键导出到环境变量时去掉 `sidecar_` 前缀并转成大写，例如 `sidecar_name` 变成 `SIDECAR_NAME`。`sidecar_args` 导出为 JSON 数组。布尔导出为 `1` 或 `0`。

### 追加一个进程

`APP_PATH/Config/sidecar.php`：

```php
<?php

declare(strict_types=1);

return [
    'sidecar_clean_sockets_on_start' => false,
    'sidecars' => [
        [
            'sidecar_name' => 'billing',
            'sidecar_enable' => true,
            'sidecar_exec_binary' => '/opt/swoolefy/bin/billing-sidecar',
            'sidecar_args' => [],
            'sidecar_socket_path' => '',
            'sidecar_plugins' => 'billing',
        ],
    ],
];
```

一项配置只能写一个插件名。插件字段类在 `SidecarPluginRegistry` 登记后，PHP 才接受这个名字。当前登记的是 `rocketmq`，字段在 `SidecarRocketMqSpec`。未登记的名字启动失败，信息是 `unknown sidecar plugin`。Go 进程里也要有同名插件，否则 `exec` 之后以 `unknown plugin` 退出。

应用要改 socket 根目录时，加上非空的 `sidecar_runtime_dir`。它会覆盖框架的 `APP_PATH`。

## 二进制

运行机器按 `PHP_OS_FAMILY` 选择文件，不在部署机上编译，也不需要安装 UPX。UPX 只在打 Linux 包时使用；压缩后的 ELF 自带解压代码，启动时在内存里展开，然后再 `listen`。macOS 产物不要用 UPX 压缩。

Go 模块语言版本是 1.26，RocketMQ SDK 固定 `github.com/apache/rocketmq-clients/golang/v5 v5.1.2`。编译命令、`upx -9` 和平台差异写在 `swoolefy-go-sidecar` 的 `README.md`。产物文件名必须是 `go-sidecar-mac` 或 `go-sidecar-linux`，放在本目录 `Bin/`。

Linux 二进制是 `CGO_ENABLED=0` 的静态 ELF。不要用 Go 1.20 编译：较新内核打开 5 级页表时，旧运行时会在启动阶段 `fatal error: lfstack.push`。

## RocketMQ 用法

方法名：

| method | 作用 |
| --- | --- |
| `sidecar.version` | 读取发布版本。payload 为空，响应只有 `version` |
| `health.check` | 读本进程状态。不向代理发探测 |
| `rocketmq.producer.send` | 发送一条消息 |
| `rocketmq.consumer.receive` | 按消费组 + Topic 拉一批；按 `consumerTag` 或 `consumerSql`（SQL92）订阅过滤 |
| `rocketmq.consumer.ack` | 用回执确认一条 |
| `rocketmq.consumer.changeInvisible` | 改一条消息的不可见时间，返回新回执（旧回执失效） |

`health.check` 的 payload 为空。固定字段：

| 字段 | 含义 |
| --- | --- |
| `version` | Go sidecar 版本，与 `sidecar.version` 相同。发新版本时改 `swoolefy-go-sidecar/internal/version/version.go` 的 `Version` 后重新编译 |
| `uptimeMs` | 进程已运行毫秒 |
| `listenerOk` | socket 是否仍在接入 |
| `inflight` | 当前在途请求数 |

RocketMQ 插件还会加上 `producerReady`、`consumerReady`、`sendOk`、`sendFail`、`sendTimeout`、`capabilities`（支持的能力列表）。这些键没出现时，`HealthCheckResponseDto` 的对应 getter 抛异常，不会把“字段缺失”读成 `false` 或 `0`。先用 `hasProducerReady()` 这类方法判断。

### 发送

在协程或开启了协程的自定义进程里：

```php
$producer = new \Swoolefy\Support\Rocketmq\RocketmqProducer(
    new \Swoolefy\Core\Sidecar\SidecarClient()
);

$version = $producer->version(3000);
$health = $producer->health(3000);

$request = new \Swoolefy\Support\Rocketmq\Dto\SendMessageRequestDto();
$request->setTopic('test-demo');
$request->setBodyBase64(base64_encode('hello'));
$request->setTag('demo');
$request->setKeys(['test-demo-1']);
$request->setDelayMs(0);
$response = $producer->send($request, 10000);
$messageId = $response->getMessageId();
```

`delayMs` 为 0 表示立即投递，大于 0 是相对当前时间的延迟毫秒，不是 Unix 时间戳。按绝对时间投递用 `setDeliverAtMs()`（Unix 毫秒）。`properties`、`messageGroup` 等字段见 `src/Support/Rocketmq/README.md`。Topic 必须落在 `sidecar_rocketmq_allowed_topics` 里；配置为 `*` 时不限制。

`send()` 可能抛出：

- `SidecarException`：还没写出字节，或对端明确返回了非 OK。可以按业务决定是否重试。
- `OutcomeUnknownException`：字节已经写出。先按消息 key 做幂等查询，再决定要不要另发一条。

### 消费进程

业务只实现 `onMessage()`。跟协议服务一起启停时继承 `RocketmqConsumerProcessHandler`，独立常驻时继承 `RocketmqConsumerWorkerProcessHandler`。注册方式、字段和确认规则见 `src/Support/Rocketmq/README.md`。

## 日志

PHP 通道 `sidecar_log`，文件是 `LOG_PATH/sidecar/sidecar.log`，按天切分并保留 2 天。一条记录只有 sidecar 名、method、requestId、code、durationMs。Go 标准输出是同一组字段：

```text
sidecar=rocketmq method=rocketmq.producer.send requestId=... code=OK durationMs=14
```

两边都不记 payload、AccessKey、SecretKey、消息正文。

RocketMQ SDK 自己的文件日志在 `rocketmq.client.logRoot` 指向的目录，文件名是 `rocketmq_client_go.log`。

## 调用示例以外的失败

| 现象 | 原因 |
| --- | --- |
| 启动后第一轮 `connect failed: rocketmq`，随后发送成功 | 业务进程先于 Go `listen`。下一轮再连即可 |
| `sidecar exec failed` | 二进制不可执行，或架构和当前机器不一致 |
| `Exec format error` | 例如在 arm64 的 macOS 上执行了 Linux ELF。看 `PHP_OS_FAMILY` 和 `Bin/` 里的对应文件 |
| `chmod ... invalid argument` | 旧二进制在 socket 文件上 `chmod`。当前 Go 在 `listen` 前设置 umask，不再对已创建的 socket 文件 `chmod` |
| `mkdir /logs: read-only file system` | 没带到 `rocketmq.client.logRoot`。确认插件名包含 `rocketmq`，并且进程是由 `SidecarSupervisor` 拉起的 |
| 发送 OK，拉取 `INTERNAL_SERVER_ERROR` 且堆栈在 `ReceiveMessageActivity.java:63` | 代理上的客户端设置没有订阅。Go 必须在 `Start` 时带上订阅表达式。先 `Start` 再 `Subscribe` 时，代理读订阅会空指针 |
| ack 返回非 OK | `message` 是这次确认的具体错误。未确认的消息在 `invisibleMs` 之后会再次投递，`deliveryAttempt` 递增 |
