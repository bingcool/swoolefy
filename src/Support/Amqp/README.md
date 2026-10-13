# AMQP

连接来自 `Config/dc.php` 的 `amqp_connection`，对应 `.env` 的 `AMQP_HOST`、`AMQP_PORT`、`AMQP_USER`、`AMQP_PASSWORD`、`AMQP_VHOST`。`host_list` 可以写多个节点，按顺序尝试。

交换机、队列和 routing key 写在业务类上。声明参数来自应用的 `Config\AmqpConfig`：队列默认持久化，消息 `delivery_mode` 为 2，消费 `prefetch_count` 固定为 1（配置写别的值也不生效，消费会话只跟踪一条未确认消息）。不再使用 `Config/component/amqp`。

发送结果：`publish()` 等 Broker 确认。Broker 返回 nack 时抛 `AmqpException`，消息没有入队，可以重发；已经发出但确认超时或连接中断时抛 `AmqpOutcomeUnknownException`（`AmqpException` 的子类），Broker 可能已经收下，直接重发可能重复。

消费端 `onMessage()` 成功后 ack。ack 失败时关闭连接，这条由 Broker 重新投递，`isRedelivered()` 为 true，业务需要按业务键幂等。

需要 Composer 包 `php-amqplib/php-amqplib`。

`read_write_timeout` 必须不小于心跳的 2 倍。配置更小时，连接时会自动抬到心跳的 2 倍。

## 发送

`AmqpProducer::publish()` 按交换机发送。同一次进程里复用连接。调用会等到 Broker 确认；确认超时后不再自动重发。传入队列名时会声明并绑定该队列，这样消费者还没启动时消息也有地方可放。Fanout 只广播交换机时，队列名留空。

```php
$producer = new \Swoolefy\Support\Amqp\AmqpProducer(
    exchange: 'order_exchange_direct',
    exchangeType: 'direct',
    queue: 'order_add_queue_direct',
    bindingKey: 'order-direct-add',
    routingKey: 'order-direct-add',
);
$producer->publish('amqp-producer:' . date('Y-m-d H:i:s'));
```

第二个参数是这次消息的 routing key，可省略。第三个参数覆盖消息属性，例如 `priority`。

延迟投递把 TTL 和死信交换机写在队列参数里。出现 `x-dead-letter-exchange` 时会声明该交换机。构造参数 `deadLetterExchangeType` 指定类型；不传时用 `AmqpConfig::COMMON_DLX_EXCHANGE_TYPE`。类型要和 Broker 上已有的同名交换机一致。

```php
$producer = new \Swoolefy\Support\Amqp\AmqpProducer(
    exchange: 'order_exchange_direct',
    exchangeType: 'direct',
    queue: 'order_add_queue_delay_direct',
    bindingKey: 'order_delay',
    routingKey: 'order_delay',
    queueArguments: [
        'x-message-ttl' => 3000,
        'x-dead-letter-exchange' => 'common_dlx_exchange',
        'x-dead-letter-routing-key' => 'order-direct-add',
    ],
);
```

## 消费

两条路径都只实现 `onMessage()`。`onMessage()` 正常返回后才 ack。它抛异常时不 ack，同一条会再次交给 `onMessage()`。

同一队列可以由多个消费进程分摊，每个进程单独建连。`consumer_tag` 会带上当前 pid。

| | `AmqpConsumerProcessHandler` | `AmqpConsumerWorkerProcessHandler` |
| --- | --- | --- |
| 父类 | `AbstractProcess` | `AbstractWorkerProcess` |
| 跑在哪 | 协议服务里的自定义进程 | `php daemon.php` / `php cron.php` 常驻 worker |
| 怎么注册 | `Event::onInit()` 里 `addProcess()` | worker 配置的 `handler` |
| 适合 | 消费跟着接口服务一起启停 | 消费独立常驻 |

```php
class AmqpConsumer extends \Swoolefy\Support\Amqp\AmqpConsumerProcessHandler
{
    protected string $exchange = 'order_exchange_direct';

    protected string $exchangeType = 'direct';

    protected string $queue = 'order_add_queue_direct';

    protected string $bindingKey = 'order-direct-add';

    protected string $routingKey = 'order-direct-add';

    public function onMessage(\Swoolefy\Support\Amqp\Dto\ReceivedMessageDto $message): void
    {
        $payload = $message->getPayload();
    }
}
```

常驻 worker 换成继承 `AmqpConsumerWorkerProcessHandler`，字段和 `onMessage()` 一样。`ReceivedMessageDto` 还有 `getExchange()`、`getRoutingKey()`、`getQueue()`、`getDeliveryTag()`、`isRedelivered()`。

生产者和消费者声明同一个队列时，队列参数必须一致，否则 Broker 会拒绝重复声明。
