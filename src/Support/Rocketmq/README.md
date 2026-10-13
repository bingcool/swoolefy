# RocketMQ 消费 Handler

两条消费路径都只做一件事：业务实现 `onMessage()`。拉取、确认、失败后的等待由基类完成。消息正文在 `ReceivedMessageDto` 里，字段是 `messageId`、`topic`、`bodyBase64`、`tag`、`key`、`receiptHandle`、`deliveryAttempt`。

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
| `$consumerTag` | `*` | Tag。空字符串也表示订阅全部 |
| `$maxNum` | 8 | 一次最多拉取条数，范围 1–32 |
| `$invisibleMs` | 30000 | 未确认前的不可见时间，范围 10000–300000 毫秒。RocketMQ proxy 拒绝小于 10 秒的值（`ILLEGAL_INVISIBLE_TIME`），小于 10000 按 10000 发送，大于 300000 按 300000 |
| `$ackSafetyMarginMs` | 2000 | 本批不可见时间到期前预留的毫秒数 |
| `$sidecarStartupWaitSeconds` | 2.0 | 进程启动后第一次 receive 前等待 go-sidecar 开始监听的秒数，范围 0–5，超过 5 按 5，0 表示不等 |

一批消息按顺序处理。每条开始前检查本批的不可见时间，离到期不足 `$ackSafetyMarginMs` 时，剩下的消息不处理也不确认，等 RocketMQ 再投；否则它可能已经投给了别的消费者，这里再处理就会重复。单条处理慢时调大 `$invisibleMs` 或调小 `$maxNum`，保证 `$maxNum × 单条耗时 < $invisibleMs - $ackSafetyMarginMs`。

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

    protected string $consumerTag = 'demo';

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

    protected string $consumerTag = '*';

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
