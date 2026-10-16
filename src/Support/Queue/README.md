# Queue

`Config/component/queue.php` 只提供 Redis 连接。默认 List 连接名是 `queue`，延迟连接名是 `delayQueue`。`Queue` 和 `RedisDelayQueue` 由生产者、消费者按自己的 `$queueKey` 创建。同一个键的生产者和消费者读写同一条队列。不同消费者写不同的键，就使用不同的队列实例。

`push()` 传入业务数组，不要自己 `json_encode`。组件会补上 `__id`、`__retry_num`、`__timestamp`。

`pop()` 成功后消息已经离开 Redis。`onMessage()` 正常返回就结束。它抛异常时，基类用原数组调用 `retry()`，`$retryDelaySeconds`（默认 1）秒后再可见。List 默认最多重试 5 次，延迟队列默认 32 次，由子类的 `$maxRetryTimes` 调整。业务如果自己 `push()` 了一条新消息，应当正常返回，不要再抛异常。

重试用完，或者消息体不是数组、无法 `retry()` 时，消息写入死信列表 `{队列键}:dead_letter`（Redis List，最新在左），不再静默丢弃。每条是 JSON，含 `queue`、`reason`（`retry_exhausted` / `not_retryable`）、`payload`、`error_class`、`error_message`、`failed_at`。死信容量始终有上限：默认只保留最新 5000 条，由 `$deadLetterMaxLength` 调整，配成 `<= 0` 时仍按 5000 处理。每次写入死信都会把 key 的过期时间刷新为 `$deadLetterTtlSeconds`（默认 7 天，`<= 0` 同样按默认值），连续这么久没有新死信时整个 key 由 Redis 自动删除，需要保留的死信要在过期前取走。

`retry()` 或写死信时 Redis 出错，消息留在内存里，之后只重做这一步，不会再次调用 `onMessage()`。

进程退出时，已经 `pop()`、还没交给 `onMessage()` 的消息原样放回队列（List 放回出队端，延迟队列放回 ZSET 并立即可见），`__retry_num` 不变。协议服务的消费进程在 SIGTERM 时最多等 `$shutdownWaitSeconds`（默认 5）秒让当前这一轮跑完。Redis 不可用时，整条消息写进异常日志，便于人工补发。

进程被 `kill -9` 或 OOM 时，正在 `onMessage()` 里的那一条会丢失：`pop()` 已经把它从 Redis 删除。需要严格不丢的业务用 Job 或 RocketMQ。

`getData()` 是解码后的数组。`getPayload()` 是它的 JSON。`getQueue()` 返回组件名。`getReadyAt()` 只在延迟消息上有值，是入队时间，不是到期时间。到期时间是入队时间加上 `push()` 的延迟秒数。

| | ListQueue | DelayQueue |
| --- | --- | --- |
| 组件 | `queue` | `delayQueue` |
| 生产者 | `ListQueueProducer::push($item)` | `DelayQueueProducer::push($item, $delaySeconds)` |
| 协议服务消费 | `ListQueueConsumerProcessHandler` | `DelayQueueConsumerProcessHandler` |
| 常驻 worker | `ListQueueConsumerWorkerProcessHandler` | `DelayQueueConsumerWorkerProcessHandler` |

## ListQueue

```php
(new \Swoolefy\Support\Queue\ListQueue\ListQueueProducer('queue:order:list'))->push(['id' => 1]);
```

```php
class RedisList extends \Swoolefy\Support\Queue\ListQueue\ListQueueConsumerProcessHandler
{
    protected string $queueComponent = 'queue';

    protected string $queueKey = 'queue:order:list';

    public function onMessage(\Swoolefy\Support\Queue\Dto\ReceivedMessageDto $message): void
    {
        $data = $message->getData();
    }
}
```

换一条 List 时，子类覆盖 `$queueKey`。换一个 Redis 连接时，覆盖 `$queueComponent`。常驻 worker 换成继承 `ListQueueConsumerWorkerProcessHandler`。

## DelayQueue

`push()` 的第二个参数是延迟秒数。

```php
(new \Swoolefy\Support\Queue\DelayQueue\DelayQueueProducer('queue:order:delay'))->push(['order_id' => 1111], 2);
```

```php
class Queue extends \Swoolefy\Support\Queue\DelayQueue\DelayQueueConsumerProcessHandler
{
    protected string $queueComponent = 'delayQueue';

    protected string $queueKey = 'queue:order:delay';

    public function onMessage(\Swoolefy\Support\Queue\Dto\ReceivedMessageDto $message): void
    {
        $data = $message->getData();
    }
}
```

未到期的消息不会交给 `onMessage()`。子类覆盖 `$queueKey` 来换一条延迟队列。常驻 worker 换成继承 `DelayQueueConsumerWorkerProcessHandler`，属性名相同。
