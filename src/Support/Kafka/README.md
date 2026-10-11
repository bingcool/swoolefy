# Kafka

Broker 列表来自 `Config/dc.php` 的 `kafka_broker_list`，对应 `.env` 的 `KAFKA_BROKER_LIST`。librdkafka 属性来自应用的 `Config\KafkaConfig`。Topic 和消费组写在业务类上，不再使用 `Config/component/kafka`。

需要 PHP 扩展 `rdkafka`。

## 发送

`KafkaProducer::produce()` 按 Topic 发送。同一次进程里复用连接，调用会等到这次发送完成或超时。

```php
$producer = new \Swoolefy\Support\Kafka\KafkaProducer('topicOrder1');
$producer->produce('kafka-producer:' . date('Y-m-d H:i:s'));
```

第二个参数是消息 key，可省略。

## 消费

两条路径都只实现 `onMessage()`。是否自动提交 offset 由 `KafkaConfig` 的 `enable.auto.commit` 决定。`onMessage()` 正常返回后还会再提交一次当前消息；它抛异常时不主动提交，同一条会再次交给 `onMessage()`。

同一个消费组 + Topic 只保留一个消费进程。

| | `KafkaConsumerProcessHandler` | `KafkaConsumerWorkerProcessHandler` |
| --- | --- | --- |
| 父类 | `AbstractProcess` | `AbstractWorkerProcess` |
| 跑在哪 | 协议服务里的自定义进程 | `php daemon.php` / `php cron.php` 常驻 worker |
| 怎么注册 | `Event::onInit()` 里 `addProcess()` | worker 配置的 `handler` |
| 适合 | 消费跟着接口服务一起启停 | 消费独立常驻，`worker_num` 保持 1 |

```php
class ConsumerKafka extends \Swoolefy\Support\Kafka\KafkaConsumerProcessHandler
{
    protected string $topic = 'topicOrder1';

    protected string $consumerGroup = 'order_group1';

    public function onMessage(\Swoolefy\Support\Kafka\Dto\ReceivedMessageDto $message): void
    {
        $payload = $message->getPayload();
    }
}
```

常驻 worker 换成继承 `KafkaConsumerWorkerProcessHandler`，字段和 `onMessage()` 一样。`ReceivedMessageDto` 还有 `getKey()`、`getTopic()`、`getPartition()`、`getOffset()`。
