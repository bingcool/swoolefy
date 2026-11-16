# Claim（数据库行级认领队列）

把待办任务放进 MySQL 表，多个副本用 `SELECT ... FOR UPDATE SKIP LOCKED` 抢行消费。认领与状态更新在同一事务，收尾用 `owner + status + attempts` 围栏，崩溃或慢任务不会留下「认领了但没人负责」的行。每行执行前还会用 `extend()` 重新武装租约，批次里排在后面的行不会因为等太久被别的副本回收。移植自 gofy 的 `support/claim`。

`rerun_allowed = 0` 的行只执行一次：handler 报错或租约过期都直接转 dead，不进入重试（at-most-once）。默认 1，即失败可重试（at-least-once）。

与 [`Support/Job`](../Job/README.md) 的区别：Job 不建表，靠 Redis / MQ 做信封与重试；Claim 自己管一张表，靠数据库行锁与租约做多副本互斥，适合任务量适中、要求「同一行只被一个副本处理」的场景。

- 核心实现：[`ClaimQueue.php`](ClaimQueue.php)、[`ClaimRunner.php`](ClaimRunner.php)
- 可运行样例：[`Test/Process/DbTaskProcess/`](../../../Test/Process/DbTaskProcess/DbTaskConsumer.php)
- 建表语句：[`claim.sql`](claim.sql)

## 环境要求

- MySQL **8**（`SKIP LOCKED` 在 8.0 才可用）
- PHP 8.4 + `ext-swoole` + `ext-mbstring`（失败原因截断用）
- `../library` 的 Db 组件，DB 连接由 `Config/component/db.php` 提供（默认组件名 `db`）

## 快速使用

### 1. 建表

```bash
mysql -uroot -p < src/Support/Claim/claim.sql
```

默认表名 `claim_job_task`。改表名时同步改 `ClaimConfig::$table`；也可以在代码里取 DDL：

```php
use Swoolefy\Support\Claim\ClaimQueue;
echo ClaimQueue::ddl('app.job_task');
```

表由业务维护，框架不迁移。`idx_claim (job_type, status, next_run_at, id)` 不能省，否则认领会退化成全表扫描并持有大量行锁。

已有旧表（没有 `rerun_allowed` 列）时补一列即可：

```sql
ALTER TABLE claim_job_task
  ADD COLUMN rerun_allowed TINYINT NOT NULL DEFAULT 1
  COMMENT '是否允许被再次消费：1允许，0只执行一次' AFTER next_run_at;
```

### 2. 写入任务（生产端）

直接往同一张表插行即可，没有专用 producer：

```php
use Swoolefy\Library\Db\Facade\Db;

$rowId = (int) Db::connect('db')
    ->table('claim_job_task')
    // claim 表没有 tenant_id，关掉全局租户拦截器，避免它改写 INSERT
    ->setOption('without_tenant_scope', true)
    ->insertGetId([
        'job_type' => 'demo.order.notify',
        'status' => 0,
        'owner' => '',
        'attempts' => 0,
        'next_run_at' => null, // NULL = 立即可认领；也可填未来的 DATETIME 做延迟任务
        'rerun_allowed' => 1,  // 0 = 只执行一次，失败/租约过期直接转 dead
        'payload' => json_encode(['taskId' => 1001], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        'created_by' => 'order-service',
    ]);
```

### 3. 消费（自定义进程 + Runner）

在 `Event::onStartRegisterProcess()` 注册自定义进程，进程里循环调 `Runner::run($owner)`：

```php
use Swoole\Coroutine;
use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Support\Claim\ClaimConfig;
use Swoolefy\Support\Claim\ClaimHooks;
use Swoolefy\Support\Claim\ClaimRunner;
use Swoolefy\Support\Claim\ClaimResult;
use Swoolefy\Support\Claim\RunnerConfig;

final class DbTaskConsumer extends AbstractProcess
{
    private bool $running = true;
    private ?ClaimRunner $runner = null;

    public function run()
    {
        while ($this->running) {
            $errors = $this->runner()->run($this->getProcessName() . '#' . posix_getpid());
            foreach ($errors as $error) {
                // 记录本轮收尾/回收异常；LEASE_LOST 不在这里，走 onFailure
            }
            Coroutine::sleep(0.5); // 没有到期任务时的空转退避
        }
    }

    public function gracefulShutdownDrain(): void
    {
        $this->running = false;
    }

    private function runner(): ClaimRunner
    {
        return $this->runner ??= ClaimRunner::fromComponent('db', new RunnerConfig(
            config: new ClaimConfig(
                table: 'claim_job_task',
                job: 'demo.order.notify',
                batch: 10,
                leaseSeconds: 30,
                maxAttempts: 5,
                backoffSeconds: 2,
                maxBackoffSeconds: 30,
                hooks: new ClaimHooks(
                    onClaim: fn (string $job, int $claimed) => null,
                    onReap: fn (string $job, int $reaped, int $dead) => null,
                    onLeaseLost: fn (string $job) => null,
                ),
            ),
            handlerTimeoutSeconds: 5.0,
            handler: function (?string $payload): int|string|null {
                if ($payload === null) {
                    return null; // 空闲轮：没有行可收尾，只适合打心跳
                }
                $data = json_decode($payload, true);
                return $data['taskId'] ?? null; // 正常返回 = 成功；抛异常 = 失败
            },
            onSuccess: fn (ClaimResult $r) => null,
            onFailure: fn (ClaimResult $r) => null,
        ));
    }
}
```

`owner` 必须能区分副本（进程名 + pid 即可）。同一 job 起多个进程扫同一张表，`SKIP LOCKED` 保证它们不会抢到同一行。`handlerTimeoutSeconds` 必须小于 `leaseSeconds`，否则构造时直接抛 `HANDLER_TIMEOUT_EXCEEDS_LEASE`。

### 4. 只要队列，不要 Runner

需要自己控制执行流程时，直接用 `ClaimQueue`：

```php
use Swoolefy\Support\Claim\ClaimException;
use Swoolefy\Support\Claim\ClaimQueue;

$queue = ClaimQueue::fromComponent('db', $config);
[$reaped, $dead] = $queue->reap(); // 回收过期租约，建议每轮先跑
foreach ($queue->claim($owner) as $task) {  // $task: ClaimTask
    try {
        $queue->extend($task); // 执行前续租；已被接管则抛 LEASE_LOST，必须跳过
        doBusiness($task->payload);
        $queue->succeed($task);
    } catch (ClaimException $e) {
        if (ClaimException::isLeaseLost($e)) {
            continue; // 副作用可能已在别处发生，绝不能重试
        }
        throw $e;
    } catch (Throwable $e) {
        $dead = $queue->fail($task, $e); // true = 次数耗尽或 rerun_allowed=0，已转 dead
    }
}
```

**`claim()` 返回的 `ClaimTask` 的 `owner` 和 `attempts` 必须原样传回 `succeed()` / `fail()` / `extend()`**：这是围栏的一部分，自己构造或篡改都会让收尾写不进去。

## 状态与围栏

| status | 含义 | 谁写入 |
|--------|------|--------|
| 0 | 待认领 | 生产端 / `fail()` 退避 / `reap()` 回收 |
| 1 | 已认领（进行中） | `claim()`，同时写 `owner`、`attempts+1`、`lease_until` |
| 2 | 已完成 | `succeed()`，清 `owner`、`lease_until`、`fail_reason` |
| 3 | 处理失败，待人工介入 | `fail()` 或 `reap()` 在次数耗尽 / `rerun_allowed = 0` 时写入 |

一轮 `ClaimRunner::run()` 的顺序：`reap()` → `claim()` → 逐行 `extend()` → handler → `succeed()` / `fail()`。

- `claim()` 用 `next_run_at <= NOW(3)` 找到期行，认领和 `status=1` 在同一事务提交；批大小默认 10，硬上限 20。
- `extend()` 在每行 handler 开始前把 `lease_until` 重置为一个完整的租约窗口，并用同一套围栏确认这行仍属于自己；已被接管则抛 `LEASE_LOST`，Runner 会跳过 handler。
- `fail()` 写失败原因和时间，未耗尽次数且 `rerun_allowed = 1` 则按指数退避写 `next_run_at` 回到 status 0；否则转 status 3。返回值 `bool $dead` 表示这次是否已置死。
- `reap()` 把 `lease_until < NOW(3)` 的行收回：`rerun_allowed = 1` 且次数未耗尽回 0，其余转 3；并发调用是幂等的。
- `succeed()` / `fail()` / `extend()` 的 SQL 都带 `WHERE id = ? AND owner = ? AND status = 1 AND attempts = ?`（`fail()` 还要求 `rerun_allowed = 1`）。匹配不到就抛 `ClaimException::LEASE_LOST`（用 `ClaimException::isLeaseLost($e)` 判断），表示这行已被别人接管，**本次收尾什么都没写**。

## 配置

`ClaimConfig`（时间单位都是秒，与 DATETIME 列一致）：

| 参数 | 默认值 | 说明 |
|------|--------|------|
| `table` | `''` | 必填，可带库名前缀如 `app.job_task` |
| `job` | `''` | 必填，对应 `job_type` 列；不同业务 job 共用一张表时靠它隔离 |
| `batch` | 10 | 每轮最多认领多少行；硬上限 `MAX_BATCH = 20`，超过直接报错而不是静默截断 |
| `leaseSeconds` | 60 | 租约；Runner 每行执行前会续租，只需大于单次 handler 最长耗时（p99 加余量） |
| `maxAttempts` | 5 | 最多认领次数（含成功那次） |
| `backoffSeconds` | 10 | 首次失败重试延迟 |
| `maxBackoffSeconds` | 600 | 退避上限，`base * 2^(attempts-1)` 封顶 |

批大小故意小：一个实例是串行处理一批的，大批次不会提高吞吐，只会拉长排队尾巴、让一个 worker 同时租住更多行。要更并行请加副本/并发，而不是加批大小。

`RunnerConfig`：

| 参数 | 默认值 | 说明 |
|------|--------|------|
| `handler` | 必填 | `fn (?string $payload): int\|string\|null`，返回值仅用于结果钩子；抛异常即失败；必须幂等 |
| `handlerTimeoutSeconds` | 5.0 | 单次 handler 的协作式超时；必须小于 `leaseSeconds`，否则构造报错 |
| `onSuccess` / `onFailure` | 空 | `fn (ClaimResult $result): void`，落库后触发 |

`ClaimHooks` 是队列级指标钩子（`onClaim` / `onReap` / `onLeaseLost`），在发起操作的协程上同步执行，不要在里面操作同一条 claim 行。结果级上下文见 `ClaimResult`：`rowId` 是表主键，`taskId` 是 handler 从 payload 解出的业务标识，`dead` / `leaseLost` / `error` / `elapsedSeconds` 可用于告警与耗时统计。

## 必须注意

1. **handler 必须幂等。** `extend()` 只保护「排队窗口」，不保护「执行窗口」：handler 跑过租约仍可能被回收、被别的副本再跑一遍；进程在副作用之后、`succeed()` 之前崩溃也会如此。收到 `LEASE_LOST` 绝不能重试副作用。
2. **超时约束不了阻塞型扩展。** `handlerTimeoutSeconds` 用框架的 `TimeoutGuard`，只能中断可协作的 Swoole 协程调用（如 `Coroutine::sleep`、协程客户端）。阻塞型客户端要自己设网络超时。
3. **空闲轮会调用一次 `handler(null)`**，可用于心跳；返回值被忽略，异常被吞，此时没有行可收尾。
4. **表没有 `tenant_id`。** 队列内部已 `setOption('without_tenant_scope', true)`；业务自己写入任务时也要关掉租户拦截器。
5. **`deleted_at` 只是普通列。** claim 不读写它，非空也不会让行从认领里消失；要软删语义需自行过滤所有查询。
6. **`rerun_allowed` 要在插入时决定。** claim 只读不写；行一旦进入待认领，再改这列已经晚了（可能已被认领）。它只影响失败/租约过期后的走向，不影响首次执行。
7. 表名只允许普通标识符（可带一层库名），所有值都走参数绑定；非法表名抛 `INVALID_TABLE`。

## 目录

| 文件 | 职责 |
|------|------|
| `ClaimQueue` | 认领、续租（`extend`）、收尾、回收、DDL；核心 SQL 与围栏 |
| `ClaimRunner` / `RunnerConfig` | 单 handler 的一轮轮询；超时、结果钩子 |
| `ClaimConfig` / `ClaimHooks` | 队列参数、默认值、指数退避、指标钩子 |
| `ClaimTask` / `ClaimResult` | 认领到的行 / 交给结果钩子的上下文 |
| `ClaimException` | 参数错误（表名 / owner / batch / 超时）、DB 错误、`LEASE_LOST` 识别 |
| `claim.sql` | MySQL 8 建表语句，与 `ClaimQueue::ddl()` 一致 |

样例进程 `DbTaskConsumer` / `DbTaskHandler` / `DbTaskProducer` 在 `Test/Process/DbTaskProcess/`，`Test/Event.php` 中由 `TEST_DB_TASK=1` 开关注册（默认关闭）：

```bash
TEST_DB_TASK=1 php cli.php start Test
```

单测：`PHPUintTest/Unit/Support/Claim/ClaimQueueTest.php`（`vendor/bin/phpunit --testsuite unit --filter ClaimQueueTest`）。
