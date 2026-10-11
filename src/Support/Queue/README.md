# Queue

队列实例定义在 `Config/component/queue.php`。底层是 `Swoolefy\Library\Queues\Queue` 和 `Swoolefy\Library\Queues\RedisDelayQueue`。

`$queue_component` 是组件名，不是 Redis 键。默认 List 组件名是 `queue`，延迟组件名是 `delayQueue`。Redis 客户端和键名写在 `queue.php` 的闭包里。生产者和消费者写同一个组件名，才会读写同一条队列。

`push()` 传入业务数组，不要自己 `json_encode`。组件会补上 `__id`、`__retry_num`、`__timestamp`。

`pop()` 成功后消息已经离开 Redis。`onMessage()` 正常返回就结束。它抛异常时，基类用原数组调用 `retry()`，1 秒后再可见。List 最多 5 次，延迟队列最多 32 次，超过后组件不再放回。业务如果自己 `push()` 了一条新消息，应当正常返回，不要再抛异常。

`getData()` 是解码后的数组。`getPayload()` 是它的 JSON。`getQueue()` 返回组件名。`getReadyAt()` 只在延迟消息上有值，是入队时间，不是到期时间。到期时间是入队时间加上 `push()` 的延迟秒数。

| | ListQueue | DelayQueue |
| --- | --- | --- |
| 组件 | `queue` | `delayQueue` |
| 生产者 | `ListQueueProducer::push($item)` | `DelayQueueProducer::push($item, $delaySeconds)` |
| 协议服务消费 | `ListQueueConsumerProcessHandler` | `DelayQueueConsumerProcessHandler` |
| 常驻 worker | `ListQueueConsumerWorkerProcessHandler` | `DelayQueueConsumerWorkerProcessHandler` |

## ListQueue

```php
(new \Swoolefy\Support\Queue\ListQueue\ListQueueProducer())->push(['id' => 1]);
```

```php
class RedisList extends \Swoolefy\Support\Queue\ListQueue\ListQueueConsumerProcessHandler
{
    protected string $queue_component = 'queue';

    public function onMessage(\Swoolefy\Support\Queue\Dto\ReceivedMessageDto $message): void
    {
        $data = $message->getData();
    }
}
```

换一个组件时，子类覆盖 `$queue_component`。常驻 worker 换成继承 `ListQueueConsumerWorkerProcessHandler`。

## DelayQueue

`push()` 的第二个参数是延迟秒数。

```php
(new \Swoolefy\Support\Queue\DelayQueue\DelayQueueProducer())->push(['order_id' => 1111], 2);
```

```php
class Queue extends \Swoolefy\Support\Queue\DelayQueue\DelayQueueConsumerProcessHandler
{
    protected string $queue_component = 'delayQueue';

    public function onMessage(\Swoolefy\Support\Queue\Dto\ReceivedMessageDto $message): void
    {
        $data = $message->getData();
    }
}
```

未到期的消息不会交给 `onMessage()`。常驻 worker 换成继承 `DelayQueueConsumerWorkerProcessHandler`。
