# RocketMQ 业务能力补齐技术方案

涉及两个仓库：`swoolefy`（PHP 侧 `src/Support/Rocketmq`）和 `swoolefy-go-sidecar`（`internal/rocketmq`）。目标是只补业务里常用、且现有架构能稳妥承载的能力，不把 Go SDK 的接口逐个搬到 PHP。

## 1. 现状

PHP 通过 UDS 调 sidecar，sidecar 用 `github.com/apache/rocketmq-clients/golang/v5 v5.1.2` 连 RocketMQ 5 proxy。

| 方法 | 已支持的字段 | 缺口 |
| --- | --- | --- |
| `rocketmq.producer.send` | `topic`、`bodyBase64`、`tag`、`key`（单个）、`delayMs`（相对时长） | 只能带一个 Key；没有自定义属性；没有顺序消息分组；不能指定绝对投递时间 |
| `rocketmq.consumer.receive` | 返回 `messageId`、`topic`、`bodyBase64`、`tag`、`key`（多个 Key 用逗号拼成一个串）、`receiptHandle`、`deliveryAttempt` | 拿不到属性、消息分组、产生时间、计划投递时间；Key 本身含逗号时无法还原 |
| `rocketmq.consumer.ack` | `consumerGroup`、`receiptHandle`、`messageId`、`topic` | 没有「延长处理时间」和「指定多久后重投」 |
| `health.check` | 版本、监听状态 | 没有能力声明，PHP 无法判断 sidecar 是否支持新字段 |

两处约束决定了实现方式：

- sidecar 的 `decodePayload()` 开了 `DisallowUnknownFields`，请求里多一个或少认一个字段都会返回 `INVALID_ARGUMENT`。本方案把 `key` 换成 `keys`，新旧协议互不兼容，**PHP 和 sidecar 必须一起发布**。sidecar 二进制本来就放在 swoolefy 仓库的 `src/Core/Sidecar/Bin/` 下随框架发布，满足这个条件。
- v5.1.2 的 Producer 没有 `Recall`（撤回延时消息），`SimpleConsumer.ChangeInvisibleDuration` 只接受 SDK 内部的 `*MessageView`。sidecar 不保存 MessageView，所以改不可见时间要像现有 `ackReceipt()` 一样，直接用 receiptHandle 调 proxy 的 `ChangeInvisibleDuration` RPC。

## 2. 范围

### 本期做

| 编号 | 能力 | 业务场景 |
| --- | --- | --- |
| A | 生产：多个 Keys、自定义 Properties | 按订单号、用户号在控制台查消息；携带事件版本、来源服务、traceId |
| B | 生产：`deliverAtMs` 绝对投递时间 | 「订单创建 30 分钟后检查支付」这类按业务时间点投递，不受 PHP 侧排队耗时影响 |
| C | 生产：`messageGroup` 顺序消息 | 同一订单的创建、支付、发货事件按序消费 |
| D | 消费：返回 Keys 数组、Properties、messageGroup、时间戳 | 消费端拿到生产端写入的元数据，用于幂等、路由、延迟监控 |
| E | 消费：`rocketmq.consumer.changeInvisible` | 单条处理耗时长时续租；业务失败时按自己的退避时间重投，而不是等满整个不可见时间 |
| F | `health.check` 返回 `capabilities` | PHP 启动或首次调用时确认 sidecar 已支持新字段，避免灰度期请求被拒 |
| G | 消费订阅过滤：Tag 表达式 / SQL92 属性过滤 | 同一个 Topic（如 `order-events`）里有订单创建、支付、取消事件，库存服务只关心其中一部分；在订阅上配置过滤条件，broker 端过滤，不投递无关消息 |

### 本期不做

| 能力 | 原因 |
| --- | --- |
| 撤回延时消息（Recall） | v5.1.2 没有该接口，需要升级 SDK，并且 RocketMQ 服务端要支持撤回（5.3.x 之后）。先用「消费时校验业务状态」替代：订单已支付，超时消息到达后直接确认丢弃。等 SDK 和服务端都升级后再单独评估 |
| 事务消息 | 需要 sidecar 在半消息回查时回调 PHP 查询本地事务状态，等于让 sidecar 反向调用业务进程，复杂度和故障面都明显增加。现有场景用「本地消息表 + 定时补发」更稳 |
| 异步发送 `SendAsync` | PHP 在 Swoole 协程里调用，`go()` 并发发送即可，没必要在 sidecar 维护回调 |
| Push Consumer、管理类接口（建 Topic、查位点等） | 不属于业务进程职责，运维走 mqadmin 或控制台 |
| 批量发送 | RocketMQ 5 gRPC SDK 的 `Send` 一次只发一条，批量收益有限；PHP 侧可以协程并发 |

## 3. 协议设计

所有新字段都是**可选**的，PHP 只在有值时序列化。唯一的不兼容点是单个 `key` 换成 `keys`，所以 PHP 和 sidecar 同版本发布（见第 5 节）。

### 3.1 `rocketmq.producer.send`

请求新增：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `keys` | `string[]` | 业务 Key，一个或多个。**替代旧字段 `key`**，`key` 从协议中删除 |
| `properties` | `map<string,string>` | 自定义属性 |
| `deliverAtMs` | `int64` | 绝对投递时间，Unix 毫秒 |
| `messageGroup` | `string` | 顺序消息分组 |

sidecar 校验（违反返回 `INVALID_ARGUMENT`，`message` 写明字段名）：

- `keys`：最多 32 个；每个非空、不超过 128 字节、不含空白字符；重复的去掉，保留首次出现的顺序。
- 请求里出现 `key` 字段会因 `DisallowUnknownFields` 返回 `INVALID_ARGUMENT`，旧调用方式会立刻暴露，不会悄悄丢 Key。
- `properties`：最多 32 个；Key 匹配 `^[A-Za-z0-9_.-]{1,64}$`，Value 不超过 1024 字节，Key + Value 总长不超过 16 KiB。Key 不能以 `__` 开头，避免和 RocketMQ 系统属性冲突。
- `delayMs` 和 `deliverAtMs` 互斥，同时大于 0 直接拒绝。
- `deliverAtMs` 必须晚于当前时间，且不超过当前时间 + 40 天（RocketMQ 默认 `timerMaxDelaySec` 是 3 天，超过服务端上限会被 proxy 拒绝，sidecar 只拦明显错误）。早于当前时间的按立即投递处理太危险，会把业务以为的「将来」变成「现在」，所以拒绝。
- `messageGroup` 不超过 64 字节；和 `delayMs` / `deliverAtMs` 互斥（RocketMQ 不允许顺序消息同时是定时消息）。
- 消息类型和 Topic 类型不匹配（例如往普通 Topic 发带 `messageGroup` 的消息）由 proxy 拒绝，sidecar 映射成 `INVALID_ARGUMENT`，不重连。

返回不变：`{"messageId": "..."}`。

Go 侧：`Sender.Send` 的参数收拢成一个结构体，避免签名越来越长。

```go
type outbound struct {
    Topic        string
    Body         []byte
    Tag          string
    Keys         []string
    Properties   map[string]string
    DeliverAt    time.Time // 零值表示立即投递
    MessageGroup string
}

type Sender interface {
    Send(ctx context.Context, msg outbound) (string, error)
    Close(ctx context.Context) error
}
```

`delayMs` 在 `Producer.Send` 里换算成 `DeliverAt = now + delay`，sdkSender 只认 `DeliverAt`，然后依次调用 `SetKeys`、`AddProperty`、`SetDelayTimestamp`、`SetMessageGroup`。

### 3.2 `rocketmq.consumer.receive`

请求不变。每条消息新增返回字段：

| 字段 | 类型 | 来源 |
| --- | --- | --- |
| `keys` | `string[]` | `MessageView.GetKeys()` |
| `properties` | `map<string,string>` | `GetProperties()`，去掉 RocketMQ 系统属性（以 `__` 开头的） |
| `messageGroup` | `string` | `GetMessageGroup()`，没有时为空串 |
| `bornTimestampMs` | `int64` | `GetBornTimestamp()`，没有时为 0 |
| `deliveryTimestampMs` | `int64` | `GetDeliveryTimestamp()`，非定时消息为 0 |

旧字段 `key`（逗号拼接）删除，只返回 `keys` 数组。

### 3.3 新方法 `rocketmq.consumer.changeInvisible`

请求：

| 字段 | 必填 | 说明 |
| --- | --- | --- |
| `consumerGroup` | 是 | 消费组 |
| `topic` | 是 | Topic |
| `messageId` | 是 | 消息 ID |
| `receiptHandle` | 是 | 当前回执 |
| `invisibleMs` | 是 | 从现在起再隐藏多久，10000–300000 毫秒，和 receive 的 `invisibleMs` 范围一致 |

返回：`{"receiptHandle": "新的回执"}`。

**proxy 每次改不可见时间都会签发新的 receiptHandle，旧回执随即失效。** 之后的 ack 或再次 changeInvisible 必须用新回执。这是本方法最容易用错的地方，PHP 封装里自动替换（见 4.2）。

实现：在 `sdk_ack.go` 旁新增 `changeInvisibleReceipt()`，复用 `ackReceipt()` 拿 `clientManager`、`accessPoint`、签名的反射代码，调用 `manager.ChangeInvisibleDuration(...)`。反射取字段的逻辑抽成一个函数，两处共用。`groupClient` 接口加一个方法：

```go
ChangeInvisible(ctx context.Context, receiptHandle, messageID, topic string, invisible time.Duration) (string, error)
```

错误映射和 ack 一致：回执过期 / 已被别的消费者拿走 → proxy 返回非 OK，映射成 `INVALID_ARGUMENT`（重试无意义）；网络错误 → `UNAVAILABLE`；超时 → `TIMEOUT`。

**服务端缺陷：** RocketMQ proxy 的 [apache/rocketmq#9583](https://github.com/apache/rocketmq/issues/9583)（修复 PR [#10259](https://github.com/apache/rocketmq/pull/10259)，2026-04）。proxy 在 changeInvisibleDuration 后删掉旧回执却没登记新回执，之后用新回执 ack 返回成功，但消息仍可能在新的不可见时间到期后重投。本地 5.3.2 实测复现（见第 8 节）。影响「续租后 ack」；不影响「指定多久后重投」（之后不 ack）。

### 3.4 `health.check`

返回新增 `capabilities`，值为字符串数组，本期为：

```json
["send.keys", "send.properties", "send.deliverAt", "send.messageGroup", "receive.meta", "consumer.changeInvisible"]
```

后续每加一项能力追加一个字符串，不改已有字符串含义。G 落地后追加 `subscribe.sql92`。

### 3.5 订阅过滤（`rocketmq.consumer.receive`）

**现状。** Tag 过滤已经有了：消费进程的 `$consumerTag` 随每次 receive 传给 sidecar，sidecar 按消费组 + Topic 订阅一次，`*` 或空表示全部，`paid || cancel` 这类 Tag 表达式原样交给 SDK。缺的是按**消息属性**过滤：Tag 只能有一个，事件类型、地区、渠道等多个维度没法都塞进 Tag。

**Tag 改成数组。** Handler 的 `$consumerTag` 从 `string` 改为 `array`（缺省 `['*']`），业务写 `['paid', 'cancel']`，不再手写 `||`。PHP 在 `RocketmqConsumeSession::tagExpression()` 里转成 SDK 的表达式再放进 UDS payload：

| `$consumerTag` | 发给 sidecar 的 `consumerTag` |
| --- | --- |
| `['*']`、`[]`、`['', ' ']`、`['paid', '*']` | `*` |
| `['paid']` | `paid` |
| `['paid', 'cancel', 'paid']` | `paid \|\| cancel`（去空白、去重、保序） |
| 元素不是字符串，或含 `\|\|` | 构造 session 时抛 `InvalidArgumentException` |

协议字段 `consumerTag` 仍是字符串，Go 侧不变。这是 PHP 侧的不兼容改动：PHP 属性类型不能在子类里改，业务子类里的 `protected string $consumerTag = 'demo';` 必须改成 `protected array $consumerTag = ['demo'];`，否则类加载时直接 Fatal，不会悄悄订阅错 Tag。

**新增字段：** receive 请求加可选字段 `consumerSql`（`string`），值是 SQL92 过滤表达式，作用在生产端写入的 `properties` 上（也可以引用 `TAGS`）：

```sql
eventType IN ('created', 'cancelled') AND region = 'cn'
```

规则：

- `consumerSql` 为空或不传：沿用 Tag 过滤，请求和现在逐字节一致，旧 sidecar 也能用。
- `consumerSql` 非空：订阅类型是 SQL92，`consumerTag` 必须是 `*` 或空，否则 `INVALID_ARGUMENT`（Tag 条件写进 SQL：`TAGS IN ('paid')`）。
- 长度不超过 4096 字节，首尾空白去掉后不能为空。SQL 语法由 broker 校验，sidecar 不解析；proxy 拒绝时映射成 `INVALID_ARGUMENT` 加状态码名，不重连（5.3.2 上语法错误的实际返回见 8.1）。
- 订阅按「消费组 + Topic」粘住：第一次 receive 时订阅，之后同一组同一 Topic 的过滤类型或表达式变了，返回 `INVALID_ARGUMENT` / `filter`（原来是 `tag`）。改过滤条件要重启消费进程。

**为什么每次 receive 都带过滤条件。** 对业务来说过滤是订阅配置：写在 Handler 字段上，`receive()` 没有参数。协议上仍然每次带上，是因为 sidecar 不持久化订阅，sidecar 被 Supervisor 重启后，下一次 receive 自己就能重新订阅，不需要 PHP 感知重启再补一次 subscribe 调用。sidecar 只在第一次见到这个消费组 + Topic 时真正调用 SDK 订阅，之后只做字符串比较。

**使用约束（写进 README）：**

- 过滤在 broker 上做，被过滤掉的消息对这个消费组视为已消费，不会再投递，也不进重试或死信。
- 同一个消费组的所有实例必须用同一个过滤条件。RocketMQ 的订阅关系属于消费组，不同实例条件不同会互相覆盖，导致丢消息或收到不该收的消息。需要不同条件就用不同消费组（例如库存服务、积分服务各一个组）。
- SQL92 要求 broker 配置 `enablePropertyFilter=true`。消息上没有表达式引用的属性时，表达式结果为 NULL，按不匹配处理，消息不会投递给这个组。
- 属性值都是字符串，比较数值时 broker 会尝试转换，生产端统一写成十进制字符串。

Go 侧：订阅状态从 `map[topic]tag` 改成 `map[topic]subscription{Kind, Expression}`；`sdkGroup.start()` 对 Tag 用 `NewFilterExpression`，对 SQL 用 `NewFilterExpressionWithType(expr, SQL92)`。

## 4. PHP 侧

### 4.1 DTO

`SendMessageRequestDto` 删除 `key` 及 `setKey()` / `getKey()`，新增 `keys`（`list<string>`）、`properties`（`array<string,string>`）、`deliverAtMs`（`int`）、`messageGroup`（`string`）及 getter/setter。只有一个 Key 时写 `setKeys([$orderNo])`。

序列化时**跳过空值**：`keys === []`、`properties === []`、`deliverAtMs === 0`、`messageGroup === ''` 都不进 JSON。

`ReceivedMessageDto` 同样删除 `key` / `getKey()`，改为 `getKeys(): array`。

现有调用点需要同步改：`Test/Process/Rocketmq/ProducerRocketmq.php`、`Test/Process/Rocketmq/ConsumerRocketmq.php`、`Test/Process/MqVerify/RocketmqVerifyConsumer.php`，以及对应单测和 README。

PHP 侧做一层轻校验（与 sidecar 规则一致，提前抛 `InvalidArgumentException`，省一次 IPC），但以 sidecar 为准。

`ReceivedMessageDto` 新增 `keys`、`properties`、`messageGroup`、`bornTimestampMs`、`deliveryTimestampMs`。旧 sidecar 不返回这些字段时取默认值（空数组、空串、0），`fromArray()` 已有的缺省逻辑即可覆盖。

新增 `ChangeInvisibleRequestDto` / `ChangeInvisibleResponseDto`。

### 4.2 Producer / ConsumeSession

`RocketmqProducer::send()` 签名不变，只是 DTO 多了字段。

`RocketmqConsumeSession` 新增：

```php
/**
 * 延长或缩短这条消息的不可见时间。成功后 $message 的 receiptHandle 被替换成新回执。
 */
public function changeInvisible(ReceivedMessageDto $message, int $invisibleMs): void
```

内部按 `effectiveInvisibleMs()` 同样的规则把 `$invisibleMs` 夹到 10000–300000，调用成功后 `$message->setReceiptHandle($new)`。后续 ack 走同一个 DTO，自然用的是新回执，业务不用关心回执更替。

### 4.3 Handler 的两个用法

不改现有「`onMessage()` 正常返回就 ack，抛异常就不 ack」的语义，只增加两个可选能力。

**续租。** 基类在调用 `onMessage()` 前把当前消息和 session 绑定，业务里长任务分段处理时调用：

```php
$this->extendInvisible(60000); // 从现在起再隐藏 60 秒
```

续租只延长当前这条消息。本批其余消息的截止时间仍按 `handleDeadlineNs()` 基于本批 receive 时刻计算，不随续租变化；它们可能在长任务期间已经到期，基类照旧跳过不处理，等 RocketMQ 重投。因此长任务场景建议 `$maxNum = 1`。

**指定重投时间。** 业务想让失败消息 30 秒后重投，而不是等满 `$invisibleMs`：

```php
throw new RocketmqRetryLaterException(30000);
```

基类捕获后调用 `changeInvisible($message, 30000)`，不 ack。`changeInvisible` 失败只记日志，消息仍按原不可见时间重投，不影响正确性。其他异常保持现状。

### 4.4 能力探测

`RocketmqProducer` 新增 `capabilities(int $timeoutMs = 0): array`，读 `health.check`。DTO 设置了新字段时，**不强制**每次发送前探测（多一次 IPC）；sidecar 不支持时会直接返回 `INVALID_ARGUMENT`，PHP 把这个错误的提示改成「sidecar 版本过旧，不支持字段 xxx，请先升级 go-sidecar」，便于定位。需要严格灰度的业务可以启动时自行调用 `capabilities()` 判断。

### 4.5 订阅过滤

两个消费 Handler 新增字段 `$consumerSql`，和 `$consumerTag` 一样是订阅配置，`receive()` 不接受过滤参数：

```php
class StockConsumer extends RocketmqConsumerProcessHandler
{
    protected string $topic = 'order-events';
    protected string $consumerGroup = 'stock-service';
    // 只要订单创建和取消
    protected string $consumerSql = "eventType IN ('created', 'cancelled')";

    public function onMessage(ReceivedMessageDto $message): void { /* ... */ }
}
```

生产端：

```php
$request->setProperties(['eventType' => 'created']);
```

- `ReceiveMessageRequestDto` 新增 `consumerSql`，为空时不序列化。
- `RocketmqConsumeSession` 第三个构造参数改为 `array $consumerTags`，末尾加 `string $consumerSql = ''`；`consumerSql` 非空且 Tag 不是全部时直接抛 `InvalidArgumentException`，进程启动即失败，不等到 receive。
- 设置了 `consumerSql` 而 sidecar 太旧（返回 `INVALID_ARGUMENT` / `payload`）时，异常信息改成「sidecar 不支持 consumerSql，请升级 go-sidecar」。

## 5. 升级顺序

`key` → `keys` 是不兼容改动，PHP 和 sidecar 二进制同一个版本发布：

1. go-sidecar 改完后重新编译，替换 swoolefy 的 `src/Core/Sidecar/Bin/go-sidecar-linux`、`go-sidecar-mac`。
2. 同一个 swoolefy 版本里改 PHP DTO 和调用点。
3. 业务项目升级 swoolefy 时，把 `setKey($x)` 改成 `setKeys([$x])`、`getKey()` 改成 `getKeys()`，消费 Handler 的 `protected string $consumerTag = 'x';` 改成 `protected array $consumerTag = ['x'];`（`'a || b'` 改成 `['a', 'b']`）。漏改的地方 PHP 会直接报方法不存在，不会悄悄丢 Key。

回滚时 PHP 和二进制一起回滚。

`consumerSql` 是纯新增的可选字段，不设置时请求不变，不影响上面的顺序；设置了就要求 sidecar 已升级，并且 broker 开启 `enablePropertyFilter`。

## 6. 测试

**sidecar（Go 单测）**

- send：各字段校验边界（keys 33 个、property key 非法、`delayMs` 与 `deliverAtMs` 同时给、`messageGroup` 与延时同时给、`deliverAtMs` 早于当前）；`delayMs` 与 `deliverAtMs` 都换算成同一个 `DeliverAt`；`keys` 去重保序；请求带 `key` 被拒。
- receive：fake groupClient 返回带属性、分组、时间戳的消息，断言 JSON；系统属性被过滤；含逗号的 Key 原样返回；响应里没有 `key`。
- changeInvisible：参数校验；返回新回执；回执失效映射成 `INVALID_ARGUMENT`。
- 旧请求（不带新字段）的 JSON 回归，结果与当前一致。
- 订阅过滤：`consumerSql` 生成 SQL92 订阅、Tag 生成 TAG 订阅；`consumerSql` 与非 `*` Tag 同时给被拒；超长 / 全空白被拒；同一组 + Topic 换条件被拒；proxy 返回 `ILLEGAL_FILTER_EXPRESSION` 映射成 `INVALID_ARGUMENT`。

**PHP（PHPUnit）**

- `SendMessageRequestDto` 只用旧字段时，序列化结果与现在逐字节一致。
- `ReceivedMessageDto::fromArray()` 对缺字段的旧响应取默认值。
- `changeInvisible()` 成功后 DTO 的 receiptHandle 被替换，随后 ack 用的是新值。
- Handler：抛 `RocketmqRetryLaterException` 时不 ack 且调用 changeInvisible；changeInvisible 失败不影响进程。
- `ReceiveMessageRequestDto` 不设 `consumerSql` 时序列化与现在一致；session 设置 `consumerSql` 后 receive 请求带上它；与非 `*` Tag 同时给时构造即抛异常。

**真实环境（容器 `swoolefy-php85-v62`，沿用 `Test/Process/MqVerify`）**

- 带 keys / properties 发送，消费端读回一致。
- `deliverAtMs = now + 15s`，消费端收到时间不早于该时刻。
- FIFO Topic + `messageGroup`，同组 5 条按序到达。
- 处理中续租 60 秒，期间消息不重投，最终 ack 成功。
- `RocketmqRetryLaterException(12000)`，约 12 秒后重投，`deliveryAttempt` 递增。
- 同一 Topic 发 `eventType` 为 created / paid / cancelled 的消息，SQL 消费组 `eventType IN ('created','cancelled')` 只收到两类，Tag 消费组 `paid` 只收到 paid；非法 SQL 返回 `INVALID_ARGUMENT`。

## 7. 改动清单

| 仓库 | 文件 | 改动 |
| --- | --- | --- |
| go-sidecar | `internal/rocketmq/producer.go` | 请求新字段、校验、`outbound` 结构 |
| go-sidecar | `internal/rocketmq/sdk_producer.go` | 按 `outbound` 设置 Keys / Property / 投递时间 / 分组 |
| go-sidecar | `internal/rocketmq/consumer.go` | 返回元数据；新增 `ChangeInvisible` 处理 |
| go-sidecar | `internal/rocketmq/sdk_consumer.go`、`sdk_ack.go` | 拷出元数据；`changeInvisibleReceipt()`，反射代码复用 |
| go-sidecar | `internal/rocketmq/plugin.go` | 注册 `rocketmq.consumer.changeInvisible`；health 加 `capabilities` |
| swoolefy | `src/Support/Rocketmq/Dto/*` | 新字段；`ChangeInvisible*Dto` |
| swoolefy | `src/Support/Rocketmq/RocketmqProducer.php` | `capabilities()`；旧 sidecar 报错提示 |
| swoolefy | `src/Support/Rocketmq/RocketmqConsumeSession.php` | `changeInvisible()` |
| swoolefy | `src/Support/Rocketmq/RocketmqConsumer*Handler.php` | `extendInvisible()`；处理 `RocketmqRetryLaterException` |
| swoolefy | `src/Support/Rocketmq/RocketmqRetryLaterException.php` | 新增 |
| swoolefy | `src/Support/Rocketmq/README.md`、`docs/Swoolefy-Go-Sidecar-UDS(1).md` | 同步协议与用法 |
| go-sidecar | `internal/rocketmq/consumer.go`、`sdk_consumer.go`、`plugin.go` | `consumerSql` 校验；订阅状态带类型；SQL92 订阅；能力 `subscribe.sql92` |
| swoolefy | `Dto/ReceiveMessageRequestDto.php`、`RocketmqConsumeSession.php`、两个 Handler | `$consumerSql` 字段与校验、旧 sidecar 提示 |

## 8. 落地结果

实现与本方案的差异：

- 逐条处理（ack / 重投 / 续租）收进 `RocketmqConsumeSession::dispatch()`，两个 Handler 共用，`extendInvisible()` 调 `extendCurrent()`。
- proxy 返回 400xx / 413xx / 431xx 时，send、receive、ack、changeInvisible 都返回 `INVALID_ARGUMENT` 加状态码名（如 `MESSAGE_PROPERTY_CONFLICT_WITH_TYPE`、`INVALID_RECEIPT_HANDLE`），send 不再因此断开重连。
- go-sidecar 版本号改为 `1.0.1`。

测试：

| 范围 | 结果 |
| --- | --- |
| go-sidecar `go test ./...` | 全部通过（新增校验边界、元数据 JSON、changeInvisible、旧 `key` 被拒、proxy 拒绝不重连等用例） |
| PHPUnit `RocketmqDtoTest`、`RocketmqConsumeSessionSidecarTest`（假 UDS sidecar） | 全部通过 |
| PHPUnit coroutine 套件 | 72 个全部通过 |
| PHPUnit unit 套件（本机 PHP 8.4） | 918 个，10 个错误；e2de4bb3 上同样是这 10 个（Auth / Workflow / HttpRoute 的常量泄漏），与本次无关 |

容器真实环境（go-sidecar-linux 1.0.1 + RocketMQ 5.3.2，Topic `rmq-ext-normal` / `rmq-ext-delay`（DELAY）/ `rmq-ext-fifo`（FIFO），顺序消费组 `rmq-ext-fifo-g`）：

| 场景 | 结果 |
| --- | --- |
| `health.check` 返回 `capabilities`，版本 1.0.1 | 通过 |
| keys 去重保序、properties、`bornTimestampMs` 读回，普通消息 `deliveryTimestampMs=0` | 通过 |
| `deliverAtMs = now + 15s`：到达时间不早于该时刻（晚 484ms），`deliveryTimestampMs` 与发送值一致 | 通过 |
| FIFO + `messageGroup`：同组 5 条按序到达 | 通过 |
| `RocketmqRetryLaterException(12000)`：`invisibleMs=60000` 的消息约 16.5 秒后重投，`deliveryAttempt` 1→2 | 通过（多出的几秒是长轮询等待） |
| 续租 + 新回执 ack：changeInvisible 成功、回执被替换、ack 返回成功 | 通过 |
| 续租 + ack 后不再重投 | **不稳定**：改完立即 ack、等 1/8/15 秒再 ack 都会在续租到期后重投；等 3 秒的两次没有重投。用旧回执 ack 同样重投。即 #9583 |

结论：续租功能可用，但在带 #9583 的 proxy 上不能保证「续租后 ack 一定生效」。长任务优先把 `$invisibleMs` 设够，续租作兜底，业务按 `messageId` 或业务 Key 幂等；proxy 升级到包含 #10259 的版本后再复测。

### 8.1 订阅过滤（G）

实现与 3.5 / 4.5 一致，另外两处：

- RocketMQ 5.3.2 的 proxy 遇到解析不了的 SQL 不返回 `ILLEGAL_FILTER_EXPRESSION`，而是 `INTERNAL_SERVER_ERROR` + `parse the consumer's subscription failed`。sidecar 只在 SQL 订阅上识别这句话，映射成 `INVALID_ARGUMENT` / `ILLEGAL_FILTER_EXPRESSION`，避免 PHP 当成可重试的 `UNAVAILABLE`。
- `sdkGroup.Subscribe` 启动失败时撤回这次订阅并用原订阅重启，一条非法表达式不影响同组其他 Topic。

测试：go-sidecar 新增 SQL / Tag 订阅、互斥、超长、换条件、proxy 拒绝、解析失败映射等用例，全部通过；PHPUnit `RocketmqDtoTest`、`RocketmqConsumeSessionSidecarTest`（receive 携带 `consumerSql`、旧 sidecar 提示、构造互斥）通过，coroutine 套件 75 个全部通过。

容器真实环境（go-sidecar-linux 1.0.1，Topic `rmq-ext-filter`，broker `enablePropertyFilter=true`）：发送 created / paid / cancelled × cn / us 共 6 条带属性消息，外加 1 条只有 Tag 没有属性的消息。

| 消费组过滤条件 | 收到 | 结果 |
| --- | --- | --- |
| SQL `eventType IN ('created','cancelled')` | created-cn、created-us、cancelled-cn、cancelled-us（无属性那条没收到） | 通过 |
| Tag `paid` | paid-cn、paid-us | 通过 |
| SQL `TAGS = 'paid' AND region = 'cn'` | paid-cn | 通过 |
| Tag `*` | 全部 7 条 | 通过 |
| SQL `eventType ==`（非法） | 首次之后稳定返回 `INVALID_ARGUMENT` / `ILLEGAL_FILTER_EXPRESSION` | 通过 |
| 同组同 Topic 换条件 | `INVALID_ARGUMENT` / `filter` | 通过 |
| Tag `paid` + SQL 同时给 | `INVALID_ARGUMENT` | 通过 |

另外观察到两点，都不是本次引入：

- 新消费组 `Start` 后的第一次 receive 常常得到 `INTERNAL_SERVER_ERROR`（`ReceiveMessageActivity.java:63` 空指针），Tag 订阅也一样，用裸 SDK 同样复现。原因是客户端设置经 telemetry 流异步上报，proxy 还没收到订阅。PHP 循环 0.5 秒后重试即恢复，只是启动时多一条错误日志。
- receive 的 `timeoutMs` 扣掉网络耗时后少于 proxy 的最小长轮询时间（5 秒）时返回 `BAD_REQUEST`。PHP 固定用 25000 毫秒，不受影响；自己直接调 sidecar 的代码不要把 receive 超时设得太小。
