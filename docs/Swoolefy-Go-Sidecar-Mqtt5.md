# MQTT5 Sidecar：与 RocketMQ 独立的第二个 Go 进程

本文是方案，不改代码。目标是：PHP 通过配置再拉起一个 MQTT 5 客户端进程，和现有 RocketMQ sidecar 互不共享连接、socket 和内存。

`src/Mqtt` 是 Swoole 自己的 MQTT Broker，客户端连进来。本文的 sidecar 是相反方向：Go 进程作为 MQTT 5 客户端，连外部 Broker。两套不要合并。

## 1. 现在已经具备的进程模型

`SidecarSupervisor::attach()` 遍历 `sidecars` 列表。每一项启用后：

1. `SidecarSpec::fromRow()` 校验，并算出自己的 socket。
2. `SidecarRegistry` 按 `sidecar_name` 登记。同名失败。
3. `SocketEndpoint::prepare()` 准备该 socket。
4. `addProcess()` 注册一个 Swoole user process，回调里 `putenv` 后 `exec` 同一份二进制。

socket 路径已经带进程名：

```text
{APP_PATH}/sidecars_sock/{服务}/{APP_NAME}-{Ymd}-{His}-{port}-{sidecar_name}.sock
```

`sidecar_runtime_dir` 当前是 `APP_PATH`。RocketMQ 的文件是 `{APP_PATH}/sidecars_sock/cli/{APP_NAME}-{Ymd}-{His}-{port}-rocketmq.sock`（cron / script / daemon 各自换对应目录）。第二个进程只要 `sidecar_name` 不同，路径自然分开。

PHP 调用按名字选进程。`SidecarClient::call($name, $method, $payload, $timeoutMs)` 用 `SidecarRegistry::get($name)` 取对应 socket，每次新建一条 Unix 连接，写一帧、读一帧、关闭。名字 `rocketmq` 不会连到名字 `mqtt5` 的 socket。

Go 侧 `cmd/sidecar` 读 `SIDECAR_PLUGINS`，只 `New` 列表里的插件，再交给 `framework.Run`。插件接口是 `Register`、`Start`、`Stop`、`Ready`。`health.check` 和 `sidecar.version` 由框架注册，不属于某个业务插件。一个进程、一个监听、一个插件列表。代理或 Broker 不可用时进程不退出，当次调用返回 `UNAVAILABLE`。

因此「两个 sidecar」不是新的进程框架。缺的是第二项配置能够通过校验，以及这份二进制认识第二个插件。

## 2. 现在还不能直接加一行 MQTT

`SidecarSpec::KEYS` 是闭集。不在列表里的 `sidecar_*` 键启动失败。MQTT 的地址、用户名现在写不进去。

`fromRow()` 对每一项都读取 `sidecar_rocketmq_proxy_endpoint` 等字段。`sidecar_plugins` 含 `rocketmq` 时才检查 `host:port`。不含时这些字段仍必须是字符串，只是允许空。MQTT 项会被填上 RocketMQ 的空字段并导出成 `SIDECAR_ROCKETMQ_*`。能跑，但配置是错的形状。

`SidecarSupervisor::spawn()` 在插件列表含 `rocketmq` 时设置 `rocketmq.client.logRoot`。MQTT 进程不应走这段。

`pluginsFrom()` 只接受 `rocketmq`。`SIDECAR_PLUGINS=mqtt5` 时进程以 `unknown plugin mqtt5` 退出。

两个进程必须使用两份 `SIDECAR_*`。环境变量写在各自的 `exec` 回调里，不能改父进程的环境。现有 `spawn()` 已经是这样。

## 3. 目标形态

仍是一份二进制，按系统选择 `go-sidecar-mac` 或 `go-sidecar-linux`。Swoole `exec` 它两次。每次的插件列表只有一个名字。

```text
Swoole Master
    |
    |-- user process rocketmq          SIDECAR_PLUGINS=rocketmq
    |       socket: ...-rocketmq.sock
    |       只连 RocketMQ Proxy
    |
    |-- user process mqtt5             SIDECAR_PLUGINS=mqtt5
            socket: ...-mqtt5.sock
            只连外部 MQTT 5 Broker
```

PHP：

```text
SidecarClient::call('rocketmq', 'rocketmq.producer.send', ...)
SidecarClient::call('mqtt5',    'mqtt5.publish', ...)
```

启用互不影响。`SIDECAR_ROCKETMQ_ENABLE` 与 `SIDECAR_MQTT5_ENABLE` 都缺省 `false`。只打开其中一个时，另一个不 `exec`，也不检查它的二进制是否可执行（未启用项今天就是直接 `return null`）。

不采用两个二进制。发布、UPX 和 `PHP_OS_FAMILY` 选择都保持一份文件。进程隔离靠两次 `exec` 和不同的 `SIDECAR_PLUGINS`，不靠把 SDK 拆成两个可执行文件。二进制体积会带上两个 SDK，单个进程启动时只初始化自己的插件。

禁止一项配置同时写 `sidecar_plugins=rocketmq,mqtt5`。一个进程只加载一个业务插件。需要两个能力就配置两项。这样 RocketMQ 的重连不会挡住 MQTT 的收发，崩溃重启也只拉起退出的那一个 user process。

## 4. PHP 配置

`SystemSidecar.php` 的 `sidecars` 增加第二项，与 RocketMQ 并列：

```php
[
    'sidecar_name' => 'mqtt5',
    'sidecar_enable' => env('SIDECAR_MQTT5_ENABLE', false),
    'sidecar_exec_binary' => dirname(__DIR__) . '/Bin/go-sidecar-' . (PHP_OS_FAMILY === 'Darwin' ? 'mac' : 'linux'),
    'sidecar_args' => [],
    'sidecar_socket_path' => '',
    'sidecar_plugins' => 'mqtt5',
    'sidecar_mqtt5_broker' => env('SIDECAR_MQTT5_BROKER', ''),
    'sidecar_mqtt5_client_id' => env('SIDECAR_MQTT5_CLIENT_ID', ''),
    'sidecar_mqtt5_username' => env('SIDECAR_MQTT5_USERNAME', ''),
    'sidecar_mqtt5_password' => env('SIDECAR_MQTT5_PASSWORD', ''),
    'sidecar_mqtt5_keep_alive' => 30,
    'sidecar_mqtt5_clean_start' => true,
],
```

`.env`：

```env
SIDECAR_MQTT5_ENABLE=false
SIDECAR_MQTT5_BROKER=127.0.0.1:1883
SIDECAR_MQTT5_CLIENT_ID=
SIDECAR_MQTT5_USERNAME=
SIDECAR_MQTT5_PASSWORD=
```

`sidecar_mqtt5_broker` 只接受 `host:port`，规则与 RocketMQ 端点相同。`client_id` 为空时，Go 使用 `{APP 传入的 SIDECAR_NAME}-{pid}`，保证两个 Swoole 实例不会抢同一个 MQTT 会话。用户名和密码可以为空；空字符串不 `putenv`。密码不进 PHP 日志，也不进 `health.check`。

`keep_alive` 单位秒，范围 1–600。`clean_start` 为真时不恢复旧会话。第一版不暴露 session expiry、will、TLS。TLS 留到 Broker 地址稳定之后再加 `sidecar_mqtt5_tls`。

应用额外进程仍写 `Config/sidecar.php`，框架项在前、应用项在后。应用不要再声明同名 `mqtt5` 或 `rocketmq`。

### 4.1 按插件校验字段

`SidecarSpec::KEYS` 分成公共键和插件键。

公共键保持现状：`sidecar_name`、`sidecar_enable`、`sidecar_exec_binary`、`sidecar_args`、`sidecar_socket_path`、五个数值、`sidecar_plugins`。

插件键只在 `sidecar_plugins` 等于该插件时允许出现，也只在那时校验：

| 插件 | 键 |
| --- | --- |
| `rocketmq` | `sidecar_rocketmq_proxy_endpoint`、`sidecar_rocketmq_access_key`、`sidecar_rocketmq_secret_key`、`sidecar_rocketmq_allowed_topics` |
| `mqtt5` | `sidecar_mqtt5_broker`、`sidecar_mqtt5_client_id`、`sidecar_mqtt5_username`、`sidecar_mqtt5_password`、`sidecar_mqtt5_keep_alive`、`sidecar_mqtt5_clean_start` |

`fromRow()` 先解析 `sidecar_plugins`。不是单个已知插件名就失败。然后再按该插件取字段。MQTT 项不再导出 `SIDECAR_ROCKETMQ_*`。RocketMQ 项不再导出 `SIDECAR_MQTT5_*`。

`spawn()` 里设置 `rocketmq.client.logRoot` 的条件保持「插件列表含 rocketmq」。MQTT 进程不设置这个变量。

`encodeRow()` 仍把本项里非空的 `sidecar_*` 编成 `SIDECAR_*`。整数和布尔的编码规则不变。

## 5. Go 进程

`pluginsFrom` 增加一支：

```go
case "mqtt5":
    item, err = mqtt5.NewFromEnv()
```

`internal/mqtt5` 与 `internal/rocketmq` 并列，只依赖 `internal/framework/plugin`。SDK 使用 Eclipse Paho 的 Go MQTT v5 客户端。不把 `src/Mqtt` 的 PHP Broker 协议栈搬进 Go。

`NewFromEnv` 读取：

| 环境变量 | 来源 |
| --- | --- |
| `SIDECAR_MQTT5_BROKER` | 必填，`host:port`，连接时加上 `tcp://` |
| `SIDECAR_MQTT5_CLIENT_ID` | 可空 |
| `SIDECAR_MQTT5_USERNAME` / `SIDECAR_MQTT5_PASSWORD` | 可空 |
| `SIDECAR_MQTT5_KEEP_ALIVE` | 秒 |
| `SIDECAR_MQTT5_CLEAN_START` | `1` 或 `0` |

`Start` 在后台连接 Broker。连不上进程不退出，`Ready()` 为假，业务方法返回 `UNAVAILABLE`。断开后按 RocketMQ Producer 同样的退避重连。`Stop` 断开客户端并丢掉尚未被 PHP 取走的入站缓冲。

`health.check` 的 `Contribute` 只在本进程写 MQTT 字段：`mqttReady`、`publishOk`、`publishFail`。RocketMQ 进程不写这些键，MQTT 进程不写 `producerReady`。PHP 侧沿用「键不存在就不要读成 false」的做法。

`sidecar.version` 仍是框架方法，两个进程返回同一个 `Version` 常量。发版仍只改这一处。

### 5.1 方法

方法名带插件前缀，避免和 RocketMQ 混在一个路由表里。某个进程的路由表里只有自己的方法；调用另一个插件的方法得到 `METHOD_NOT_FOUND`。

| 方法 | 作用 |
| --- | --- |
| `mqtt5.publish` | 发一条。字段：`topic`、`bodyBase64`、`qos`（0–2）、`retain` |
| `mqtt5.subscribe` | 向 Broker 订阅。字段：`topicFilter`、`qos`。订阅关系留在这个进程的客户端上 |
| `mqtt5.unsubscribe` | 取消一个 filter |
| `mqtt5.receive` | 取出已经到达、尚未交给 PHP 的消息，最多 `maxNum`（1–32）。没有消息时在本次超时内等待，超时返回 `OK` 和空列表 |

`receive` 的单条字段：`topic`、`bodyBase64`、`qos`、`retain`、`messageId`。`messageId` 是本进程生成的序号，只用于日志，不是 Broker 的确认回执。

QoS 1/2 的报文确认由 Paho 在收到 PUBLISH 时完成。PHP 的 `receive` 表示「交给业务」，不再向 Broker 做第二次 ack。这和 RocketMQ 的 `rocketmq.consumer.ack` 不同，MQTT 进程不提供 `mqtt5.ack`。业务幂等使用 topic 加业务主键，不使用 sidecar 的 `messageId`。

Go 不跑业务循环，也不调用 PHP。入站只做一件事：SDK 回调把消息放进有界队列（默认 1024）。队列满时丢掉新消息并计 `dropCount`，`health.check` 能看到。PHP 消费进程自己循环调用 `receive`，和 RocketMQ 的两个 Handler 一样：`onMessage()` 抛异常时，本条留在 PHP 内存里重试，不要求 Broker 重投。QoS 0 本来就可能丢；第一版接受这一点。

`publish` 在第一个字节已经写到 Broker 之后如果结果不明，按现有 `OutcomeUnknownException` 处理，不自动再发。

同一进程上，`subscribe` 与 `receive` 可以并发（多条 Unix 连接）。`receive` 从同一条入站队列取走消息，取走即不再返回给下一次调用。不需要 RocketMQ 那种「同一消费组 + topic 同时只能有一个 receive」的限制。

## 6. PHP 调用层

新增 `src/Support/Mqtt5/`，不放进 `src/Mqtt/`。

| 类 | 作用 |
| --- | --- |
| `Mqtt5Client` | 固定 `SidecarClient::call('mqtt5', ...)` |
| `Mqtt5Producer` | `publish()` |
| `Mqtt5ConsumerProcessHandler` | 继承 `AbstractProcess`，循环 `receive`，业务实现 `onMessage()` |
| `Mqtt5ConsumerWorkerProcessHandler` | 继承 `AbstractWorkerProcess`，用 `loopHandle()` |

Handler 上的业务字段是 `topicFilter` 和 `qos`。进程启动后先 `subscribe`，再进入接收循环。`onMessage(ReceivedMessageDto)` 只看到正文和 topic。确认语义见 5.1，基类不再调用 ack。

DTO 与 RocketMQ 一样，请求响应都是纯数组，嵌套对象先 `toArray()`。正文 Base64。日志只记 sidecar 名、方法、`requestId`、`code`、耗时。

Test 进程与 RocketMQ 的 Test 进程分开注册。默认在 `Event.php` 里注释掉。

## 7. 隔离要求

| 项 | RocketMQ 进程 | MQTT5 进程 |
| --- | --- | --- |
| `sidecar_name` | `rocketmq` | `mqtt5` |
| socket | `...-rocketmq.sock` | `...-mqtt5.sock` |
| `SIDECAR_PLUGINS` | `rocketmq` | `mqtt5` |
| 外部连接 | RocketMQ Proxy | MQTT 5 Broker |
| 崩溃 | Swoole 只重启这个 user process | 同上 |
| 关闭开关 | 不 `exec`，不影响对方 | 同上 |

两个进程不共享 Unix 连接，不共享 Go 里的 map，也不共享 Broker TCP 连接。PHP 协程池也不跨名字复用 `Swoole\Coroutine\Client`。

`sidecars_sock/` 继续被 `.gitignore` 忽略。两个 socket 都在这个目录。

## 8. 失败

| 情况 | 结果 |
| --- | --- |
| 只启用 MQTT，Broker 还没监听 | 进程保持运行，`publish` / `subscribe` 返回 `UNAVAILABLE`，退避重连 |
| `SIDECAR_PLUGINS` 写成 `rocketmq,mqtt5` | PHP 启动失败，不 `exec` |
| 对 RocketMQ 进程调用 `mqtt5.publish` | `METHOD_NOT_FOUND` |
| MQTT 进程退出 | Swoole 重启该进程。订阅在 `Start` 完成重连后，由 PHP Handler 再次 `subscribe`。Go 不记住 PHP 上次的订阅作为隐式状态；重连后的第一次 `receive` 若尚未 `subscribe`，返回 `UNAVAILABLE` |
| 入站队列满 | 丢弃新消息，`health.check` 增加 `dropCount` |

重连后的订阅由消费进程负责，是为了避免 Go 在 PHP 已经不再消费时仍向 Broker 订阅。

## 9. 实施顺序

1. `SidecarSpec` 改为按单个插件校验字段。补上「MQTT 项不含 RocketMQ 键」和「双插件名被拒绝」的单元测试。RocketMQ 现有测试保持通过。
2. `SystemSidecar.php` 增加 `mqtt5` 项，缺省关闭。
3. Go 增加 `internal/mqtt5` 和 `pluginsFrom` 分支。先做 `publish` 的集成测试，Broker 用现有本机 Docker。
4. 补 `subscribe` 与 `receive`，以及有界队列。
5. PHP `Support/Mqtt5` 的生产者和两个消费 Handler。
6. 重新编译 mac 与 linux 二进制。两个进程使用同一对新文件。

## 10. 这一版不做

- 不把 `src/Mqtt` Broker 改成把流量转给 sidecar。
- 不做 TLS、Will、共享订阅、请求/响应主题。
- 不在 Go 里写业务回调或长期缓存消息体。
- 不在一个 Go 进程里同时加载 RocketMQ 和 MQTT。
- 不拆成两个可执行文件。
