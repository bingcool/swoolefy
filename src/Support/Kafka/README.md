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

两条路径都只实现 `onMessage()`。offset 何时提交由 handler 的 `$commitMode` 决定（`KafkaCommitMode`）：

| `$commitMode` | 何时提交 | 语义 | `onMessage()` 抛异常 |
| --- | --- | --- | --- |
| `KafkaCommitMode::AFTER_HANDLED`（默认） | 成功后同步 `commit()` | 至少一次 | 不提交，指数退避重试至多 `$maxRetryAttempts` 次（默认 5），耗尽后记录并提交 offset 丢弃 |
| `KafkaCommitMode::AFTER_HANDLED_ASYNC` | 成功后 `commitAsync()` | 至少一次，吞吐更高 | 不提交，指数退避重试至多 `$maxRetryAttempts` 次（默认 5），耗尽后记录并提交 offset 丢弃 |
| `KafkaCommitMode::BEFORE_HANDLE` | `onMessage()` 前同步 `commit()` | 至多一次 | 不重试，记错误日志后丢弃 |

```php
class NotifyConsumer extends KafkaConsumerWorkerProcessHandler
{
    protected string $topic = 'notify';
    protected string $consumerGroup = 'notify-sender';
    // 宁可漏发也不能重复发
    protected string $commitMode = KafkaCommitMode::BEFORE_HANDLE;
}
```

- `AFTER_HANDLED`：提交失败只重试提交（最多 3 次），不会再跑一次 `onMessage()`；仍失败就放弃这次提交，下一条提交成功时 offset 一并前进。在那之前发生重平衡或重启，这条会再投递一次，业务需要按 topic + partition + offset 幂等。
- `AFTER_HANDLED_ASYNC`：不等 broker 应答，省掉每条一次的往返。提交失败由 offset_commit_cb 记 warning（`kafka async commit failed`）。崩溃 / 重平衡时，已处理但异步提交还没落地的几条会再投递，重复比默认模式多，同样需要幂等。
- `BEFORE_HANDLE`：提交成功才调用 `onMessage()`；提交失败不处理，0.5 秒后重试提交，所以不会出现“没提交却已处理”。`onMessage()` 抛异常或进程崩溃时这条不会再投递，日志 `kafka message dropped after onMessage failed in before_handle mode` 带 topic / partition / offset / key，需要时据此补偿。
- `$maxRetryAttempts`（默认 5）只作用于 AFTER_HANDLED / AFTER_HANDLED_ASYNC：同一条消息连续失败达到上限后，记录 `kafka message dropped after retry attempts exhausted`（带 attempts）并提交这条的 offset，让分区继续前进；提交未落地时重平衡或重启仍可能再投递一次。设为 0 或负数恢复无限重试。
- 两次重试之间指数退避：`$retryBackoffBaseSeconds`（默认 1 秒）起，每次翻倍，单次封顶 `$retryBackoffMaxSeconds`（默认 15 秒）。默认参数下 5 次尝试之间的等待是 1+2+4+8 秒，即给下游约 15 秒的抖动恢复窗口——丢弃是不可逆动作，数据库重启、依赖发布这类几十秒的故障不应该把消息判成毒消息。停机标志出现时等待按 0.2 秒分片立即结束，这条消息不提交，重启后由 broker 重投。下游恢复更慢就加大 `$maxRetryAttempts`/`$retryBackoffMaxSeconds`，或设为 0 无限重试并自行做告警。

librdkafka 的 `enable.auto.commit` 始终强制关闭：它在 `consume()` 返回时就记下 offset 并定时提交，不看 `onMessage()` 的结果，崩溃时既可能丢也可能重复。`KafkaConfig` 里配成 1 时启动会打 warning（`kafka enable.auto.commit is ignored`），请改用 `$commitMode`。

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
