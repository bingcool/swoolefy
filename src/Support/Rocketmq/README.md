# RocketMQ 消费 Handler

两条消费路径都只做一件事：业务实现 `onMessage()`。拉取、确认、失败后的等待由基类完成。消息正文在 `ReceivedMessageDto` 里，字段是 `messageId`、`topic`、`bodyBase64`、`tag`、`keys`、`properties`、`messageGroup`、`bornTimestampMs`、`deliveryTimestampMs`、`receiptHandle`、`deliveryAttempt`。发送端的用法见文末「发送」。

`onMessage()` 正常返回后才向 RocketMQ 确认。它抛异常，或确认失败，都不确认。不可见时间结束后，这条消息会再次投递，`deliveryAttempt` 递增。业务按 `messageId` 做幂等。

同一个消费组 + Topic 同时只能有一个进程在拉取。第二个 `receive` 会立刻得到 `OVERLOADED`。

| | `RocketmqConsumerProcessHandler` | `RocketmqConsumerWorkerProcessHandler` |
| --- | --- | --- |
| 父类 | `Swoolefy\Core\Process\AbstractProcess` | `Swoolefy\Worker\AbstractWorkerProcess` |
| 跑在哪 | HTTP、WebSocket、UDP 等协议服务里的自定义进程 | `php daemon.php` / `php cron.php` 拉起的常驻 worker |
| 怎么注册 | `Event::onInit()` 里 `ProcessManager::addProcess()` | Daemon 或 Cron 的 worker 配置，`handler` 指向业务类 |
| 循环和退出 | 基类自己的 `while`。收到退出信号后，当前这条处理完就停 | 沿用 worker 的 `loopHandle()`。当前这批处理完再退出，并按 `life_time` 重启 |
| 适合 | 消费要跟着接口服务一起启停 | 消费是独立常驻任务，不依赖某个端口服务是否在跑 |

两条路径不要订阅同一个消费组 + Topic。

公共字段：

| 字段 | 缺省 | 含义 |
| --- | --- | --- |
| `$topic` | 空，必填 | Topic |
| `$consumerGroup` | 空，必填 | 消费组 |
| `$consumerTag` | `['*']` | 要订阅的 Tag 数组，例如 `['paid', 'cancel']`，收到其中任意一个 Tag 的消息。包含 `'*'` 或为空数组时订阅全部。发给 sidecar 前转成 SDK 表达式 `paid \|\| cancel`；元素必须是字符串，不能含 `\|\|` |
| `$consumerSql` | 空 | SQL92 属性过滤，例如 `eventType IN ('created','cancelled')`。非空时 `$consumerTag` 保持 `['*']`。见下文「订阅过滤」 |
| `$maxNum` | 8 | 一次最多拉取条数，范围 1–32 |
| `$invisibleMs` | 30000 | 未确认前的不可见时间，范围 10000–300000 毫秒。RocketMQ proxy 拒绝小于 10 秒的值（`ILLEGAL_INVISIBLE_TIME`），小于 10000 按 10000 发送，大于 300000 按 300000 |
| `$ackSafetyMarginMs` | 2000 | 本批不可见时间到期前预留的毫秒数 |
| `$sidecarStartupWaitSeconds` | 2.0 | 进程启动后第一次 receive 前等待 go-sidecar 开始监听的秒数，范围 0–5，超过 5 按 5，0 表示不等 |

一批消息按顺序处理。每条开始前检查本批的不可见时间，离到期不足 `$ackSafetyMarginMs` 时，剩下的消息不处理也不确认，等 RocketMQ 再投；否则它可能已经投给了别的消费者，这里再处理就会重复。单条处理慢时调大 `$invisibleMs` 或调小 `$maxNum`，保证 `$maxNum × 单条耗时 < $invisibleMs - $ackSafetyMarginMs`。

## 订阅过滤

同一个 Topic 可以有多个消费组，各自只拿自己关心的消息。过滤在 broker 上做，被过滤掉的消息不会投递给这个组。过滤是订阅配置，写在 Handler 字段上，`receive()` 不接受过滤参数。

例如 `order-events` 里有订单创建、支付、取消事件，生产端把事件类型写进 Tag 和属性：

```php
$request->setTag('created');
$request->setProperties(['eventType' => 'created', 'region' => 'cn']);
```

库存服务只关心创建和取消：

```php
class StockConsumer extends \Swoolefy\Support\Rocketmq\RocketmqConsumerProcessHandler
{
    protected string $topic = 'order-events';
    protected string $consumerGroup = 'stock-service';
    protected string $consumerSql = "eventType IN ('created', 'cancelled')";

    public function onMessage(\Swoolefy\Support\Rocketmq\Dto\ReceivedMessageDto $message): void
    {
    }
}
```

只按 Tag 过滤不需要 SQL：`protected array $consumerTag = ['created', 'cancelled'];`。Tag 和属性一起过滤时写进 SQL：`TAGS = 'paid' AND region = 'cn'`。`$consumerSql` 和具体的 `$consumerTag` 同时设置，进程启动时直接抛 `InvalidArgumentException`。

注意：

- SQL92 需要 broker 配置 `enablePropertyFilter=true`。
- 消息上没有表达式引用的属性时视为不匹配，不会投递。
- 被过滤掉的消息对这个组算已消费，不进重试，也不进死信。
- 订阅关系属于消费组。同一个消费组的所有实例必须用同一个过滤条件；条件不同就用不同的消费组。
- 修改过滤条件要重启消费进程。sidecar 不重启时，同一消费组 + Topic 换条件返回 `INVALID_ARGUMENT` / `filter`。
- SQL 语法错误时 receive 返回 `INVALID_ARGUMENT` / `ILLEGAL_FILTER_EXPRESSION`，日志里会持续出现，改配置后重启。
- 旧版 go-sidecar 不认识 `consumerSql`，异常信息会提示升级。可以用 `RocketmqProducer::capabilities()` 检查是否包含 `subscribe.sql92`。

拉取超时 25000 毫秒，确认超时 5000 毫秒。确认失败只记日志（带 `messageId`，`outcomeUnknown` 表示请求已发出但没拿到结果），不退出进程。安静队列返回空列表，基类等待后再拉。

## 跟协议服务一起跑

接口服务启动时，在 `Event::onInit()` 里单独注册一个进程。Test 里的 `rocketmq-consumer-test-demo` 就是这条路径。服务停掉时，这个消费进程一起停。

```php
ProcessManager::getInstance()->addProcess(
    'rocketmq-consumer-test-demo',
    \Test\Process\Rocketmq\ConsumerRocketmq::class
);
```

```php
class ConsumerRocketmq extends \Swoolefy\Support\Rocketmq\RocketmqConsumerProcessHandler
{
    protected string $topic = 'test-demo';

    protected string $consumerGroup = 'test-demo-group';

    protected array $consumerTag = ['demo'];

    public function onMessage(\Swoolefy\Support\Rocketmq\Dto\ReceivedMessageDto $message): void
    {
        $body = base64_decode($message->getBodyBase64(), true);
    }
}
```

Sidecar 也由这个协议服务拉起。进程刚起来的第一轮可能连不上 Unix socket，基类会等待后重试。

## 独立常驻 worker

消费不跟着某个 HTTP 端口走，用 Daemon 常驻进程。`php script.php start App --c=gen:daemon:service` 会生成 `WorkerDaemon` 目录。配置写在该目录的 `conf` 里，`worker_num` 保持 1。

```php
[
    'process_name' => 'rocketmq-consumer-order',
    'handler' => \App\WorkerDaemon\ConsumerRocketmq::class,
    'worker_num' => 1,
    'max_handle' => 10000,
    'life_time' => 3600,
    'args' => [],
]
```

```php
class ConsumerRocketmq extends \Swoolefy\Support\Rocketmq\RocketmqConsumerWorkerProcessHandler
{
    protected string $topic = 'order';

    protected string $consumerGroup = 'order-consumer';

    protected array $consumerTag = ['*'];

    public function onMessage(\Swoolefy\Support\Rocketmq\Dto\ReceivedMessageDto $message): void
    {
        $body = base64_decode($message->getBodyBase64(), true);
    }
}
```

启动：

```bash
php daemon.php start App
php daemon.php start App --daemon=1
```

这个 worker 进程里也会拉起本机 sidecar。`life_time` 到点后，当前这批 `onMessage()` 结束再重启进程。未确认的消息由 RocketMQ 再次投递。

Cron 服务如果要常驻消费，同样继承 `RocketmqConsumerWorkerProcessHandler`，用 `php cron.php start App` 启动。定时任务本身仍走 Cron 引擎，不要把消费循环写进一次 cron 回调里。

## 指定多久后重投

业务暂时处理不了（比如依赖的数据还没到），不想等满 `$invisibleMs`，在 `onMessage()` 里抛 `RocketmqRetryLaterException`：

```php
public function onMessage(ReceivedMessageDto $message): void
{
    if (!$this->stockReady($message)) {
        throw new \Swoolefy\Support\Rocketmq\RocketmqRetryLaterException(30000); // 30 秒后再投
    }
}
```

基类不确认这条消息，把它的不可见时间改成 30 秒，也不调用 `onHandleException()`。毫秒数按 10000–300000 夹取。消息重新可见后，要等下一次长轮询才拉到，所以实际重投会晚几秒到十几秒。改不可见时间失败只记日志，消息仍按原 `$invisibleMs` 重投。

## 长任务续租

单条处理时间可能超过 `$invisibleMs` 时，在 `onMessage()` 里分段续租：

```php
public function onMessage(ReceivedMessageDto $message): void
{
    foreach ($this->chunks($message) as $chunk) {
        $this->extendInvisible(60000); // 从现在起再隐藏 60 秒
        $this->handleChunk($chunk);
    }
}
```

续租只延长当前这一条，本批其余消息的截止时间不变，所以长任务建议 `$maxNum = 1`。返回 `false` 表示续租失败（已记日志），消息可能被别的消费者拿到。

> 服务端限制：RocketMQ proxy 有缺陷 [apache/rocketmq#9583](https://github.com/apache/rocketmq/issues/9583)。修复之前（5.3.x 及更早），续租后的 ack 虽然返回成功，消息仍可能在续租时间到期后再投一次。在这些版本上，长任务优先把 `$invisibleMs` 设够，续租只作兜底；业务必须按 `messageId` 或业务 Key 幂等。「指定多久后重投」不依赖之后的 ack，不受影响。

## 发送

```php
$request = new \Swoolefy\Support\Rocketmq\Dto\SendMessageRequestDto();
$request->setTopic('order');
$request->setBodyBase64(base64_encode(json_encode($order)));
$request->setTag('paid');
$request->setKeys([$order['order_no'], 'USER_' . $order['user_id']]);
$request->setProperties(['traceId' => $traceId, 'event.version' => '2']);
$response = (new \Swoolefy\Support\Rocketmq\RocketmqProducer(new \Swoolefy\Core\Sidecar\SidecarClient()))->send($request, 10000);
```

| 字段 | 说明 |
| --- | --- |
| `tag` | 消息分类，消费组按 Tag 订阅过滤 |
| `keys` | 业务 Key，最多 32 个，每个不超过 128 字节、不含空白。Broker 为每个 Key 建索引，用于在控制台按 Key 查消息；不唯一、不去重、不影响消费。重复的自动去掉 |
| `properties` | 自定义属性，消费端 `getProperties()` / `getProperty()` 读回。最多 32 个；Key 只能是字母、数字、`_`、`.`、`-`，不能以 `__` 开头；Value 不超过 1024 字节；合计不超过 16 KiB |
| `delayMs` | 相对延时（毫秒），从 sidecar 收到请求起算 |
| `deliverAtMs` | 绝对投递时间，Unix 毫秒时间戳（13 位），例如 `(int) (microtime(true) * 1000) + 30 * 60 * 1000`。必须晚于当前时间。传成秒级时间戳会直接报错 |
| `messageGroup` | 顺序消息分组，同组按发送顺序消费 |

规则：

- `delayMs` 和 `deliverAtMs` 只能给一个。
- 延时消息要发到 `message.type=DELAY` 的 Topic，顺序消息要发到 `message.type=FIFO` 的 Topic，消费顺序消息的消费组要开启顺序消费（`mqadmin updateSubGroup -o true`）。类型不匹配时 sidecar 返回 `INVALID_ARGUMENT`，错误信息是 RocketMQ 的状态码名，例如 `MESSAGE_PROPERTY_CONFLICT_WITH_TYPE`。
- `messageGroup` 不能和延时同时使用。
- RocketMQ 服务端默认最多延时 3 天（`timerMaxDelaySec`），超过会被拒绝。

需要确认 sidecar 二进制版本是否支持这些字段时，调用 `RocketmqProducer::capabilities()`，返回值包含 `send.keys`、`send.properties`、`send.deliverAt`、`send.messageGroup`、`receive.meta`、`consumer.changeInvisible`、`subscribe.sql92`。
