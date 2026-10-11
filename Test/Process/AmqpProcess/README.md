# AMQP 测试进程

连接账号在 `Config/dc.php` 的 `amqp_connection`。交换机、队列和键都在 `AmqpConst`。

`bindingKey` 是队列绑到交换机的规则。`routingKey` 是打在某一条消息上的键。交换机拿消息的 `routingKey` 去比每个队列的 `bindingKey`，决定消息进哪些队列。

| | 生产 | 消费 |
| --- | --- | --- |
| `bindingKey` | 声明队列时，把这个队列绑到交换机上。传入了队列名才会做这次绑定 | 把本进程的队列绑到交换机上，决定这个队列收哪些消息 |
| `routingKey` | `publish()` 打在这一次消息上的键。第二个参数可以覆盖构造时的默认值 | 本进程不发消息。只有 `bindingKey` 为空时，才用它顶替绑定键 |

`onMessage()` 里的 `getRoutingKey()` 是这条消息实际带的键，不是消费类上的 `$routingKey` 字段。

三种交换机：

- **direct**：`routingKey` 必须和队列 `bindingKey` 完全相同，消息才进得去。
- **topic**：`bindingKey` 是模式，`*` 是一个词，`#` 是零个或多个词。`routingKey` 是具体的键，两者通常不是同一个字符串。
- **fanout**：忽略这两个键。绑在这个交换机上的每个队列都收到同一条消息。两个键都留空。

同一个队列的声明参数必须一致。例如 Direct 业务队列两边都写了 `x-max-priority`，只在一边写会被 Broker 拒绝。

## 直接投递

这些进程不经过延迟队列。`Test/Event.php` 里默认没有打开。

| 模式 | 生产 | 消费 | 键 |
| --- | --- | --- | --- |
| direct | `AmqpPublish` | `AmqpConsumer` | 生产和消费都是 `order-direct-add`。生产把它同时当作 `bindingKey` 和 `routingKey`；消费的 `bindingKey` 等于它 |
| fanout | `AmqpPublishFanout` | `AmqpConsumerFanout`、`AmqpConsumerFanout1` | 不设置键。两个消费队列绑在同一个交换机上，都会收到每一条消息 |
| topic | `AmqpPublishTopic` | `AmqpConsumerTopic` | 生产 `routingKey` 是 `orderSaveEvent.send`。消费 `bindingKey` 是 `orderSaveEvent.#` |

## 延迟 10 秒后进死信队列

`Test/Event.php` 里这 6 个进程是打开的。消息先进入延迟队列，没有消费者去拉它。10 秒后 Broker 把它投到死信交换机，再由死信消费进程收下。

延迟这一段和死信这一段的键不要混用：

1. 生产时的 `bindingKey` / `routingKey` 只决定消息能不能先进延迟队列。
2. 到期后 Broker 改用 `x-dead-letter-routing-key` 作为新的 `routingKey`。死信消费的 `bindingKey` 要匹配这个新键。没写这个参数时，沿用消息原来的 `routingKey`。Fanout 忽略它。

| 模式 | 生产 | 消费 | 延迟键 | 死信键 |
| --- | --- | --- | --- | --- |
| direct | `AmqpPublishDlxDirect` | `AmqpConsumerDlxDirect` | `bindingKey` 和 `routingKey` 都是 `order.delay.direct` | 到期后的 `routingKey` 和消费 `bindingKey` 都是 `order.dlx.direct` |
| fanout | `AmqpPublishDlxFanout` | `AmqpConsumerDlxFanout` | 不设置键 | 不设置键 |
| topic | `AmqpPublishDlxTopic` | `AmqpConsumerDlxTopic` | 生产 `routingKey` 是 `order.delay.topic.demo`，延迟队列 `bindingKey` 是 `order.delay.topic.#` | 到期后的 `routingKey` 是 `order.dlx.topic.demo`，消费 `bindingKey` 是 `order.dlx.topic.#` |

消费日志里的 `publishedAt` 和 `receivedAt` 会相差约 10 秒。

`/api/amqp/publish-delay-direct` 是另一条延迟队列，停 3 秒后死信到 `common_dlx_exchange`，键是 `order_delay`，不是上面这组 10 秒测试。
