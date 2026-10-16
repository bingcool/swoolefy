# Job（轻量信封 + Runner）

在**现有自定义进程消费**之上提供统一 Job 信封、Handler、Registry 与重试/退避，**不新建 SQL 表**。

完整方案：[docs/Job.md](../../../docs/Job.md)

## 快速使用

### 1. 生产（HTTP）

```php
use Swoolefy\Support\Job\JobComponentFactory;

$publisher = JobComponentFactory::publisher(
    static fn (array $data) => (new \Swoolefy\Support\Queue\ListQueue\ListQueueProducer())->push($data),
);

$publisher->dispatch('order.paid.notify', [
    'orderId' => 10001,
], [
    'tenantId' => 't1',
    'idempotencyKey' => 'order:10001:paid-notify',
]);
```

### 2. 多 Handler 共进程（Registry）

```php
$registry = JobComponentFactory::registry(
    new OrderPaidNotifyHandler(),
    new OrderExportHandler(),
);
$runner = JobComponentFactory::runner();
$dlq = JobComponentFactory::redisDeadLetter($redis);

$runner->runRegistered($registry, $job, $requeue, static function ($j, $e) use ($dlq) {
    $dlq->push($j, $e);
});
```

Demo 进程：

| 进程 | 说明 |
|------|------|
| `JobRedisMultiConsumer` | 组件 `queue`，Registry 多 jobType。延迟重试写入组件 `delayQueue` |
| `OrderNotifyConsumer` | 组件 `delayQueue`，只处理到期的 `order.paid.notify` |
| `JobAmqpConsumer` | AMQP + Registry |
| `JobKafkaConsumer` | Kafka + Registry |

`Test/Event.php` 中有注释注册示例。

### 3. 配置

模版：`src/Stubs/job.conf.stub.php`（`create` 复制为 `Config/job.php`）。

```bash
# 已有应用可手动复制
cp src/Stubs/job.conf.stub.php App/Config/job.php
```

### 4. 死信重放

```php
$dlq = JobComponentFactory::redisDeadLetter(App::getRedis());
$dlq->replay(fn (array $d) => (new \Swoolefy\Support\Queue\ListQueue\ListQueueProducer('job:order:list'))->push($d), 'default', 10);
```

重放时每条先 `RPOPLPUSH` 到 `{key}:replaying`，`$publish` 成功后才删除。`$publish` 抛异常时这条放回死信 List，异常继续抛出。结构损坏的条目移到 `{key}:corrupt`。进程在重放中途退出时，下次 `replay()` 会先把暂存的条目移回再重放，可能重放两次，消费端按 `jobId` 幂等。同一个 queue 不要并发运行多个 replay。

CLI（需 Application 上下文）：

```bash
php src/Support/Job/Console/replay_dead_letter.php --queue=default --limit=10 --list-key=job:order:list
```

### 5. 测试

```bash
composer test:job
```

## 目录

| 类 | 职责 |
|----|------|
| `JobEnvelope` | 统一信封 |
| `JobResult` / `JobResultStatus` | Handler 返回值 |
| `JobRetryPolicy` | 次数 + 指数退避 |
| `JobRunner` | 执行 Handler；`runRegistered` 走 Registry |
| `JobHandlerRegistry` | jobType → Handler |
| `JobPublisher` | 组信封并 publish |
| `JobConfig` / `JobComponentFactory` | 配置与装配 |
| `RedisDeadLetter` | Redis List 死信 / replay |

死信默认：Redis List `job:dead:{queue}`，无 DB。
