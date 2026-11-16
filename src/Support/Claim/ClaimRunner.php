<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Swoolefy\Support\Claim;

use Closure;
use Swoolefy\Library\Db\BaseQuery;
use Swoolefy\Support\Workflow\Engine\TimeoutGuard;
use Throwable;

/**
 * ClaimQueue 之上的「单回调」层。对应 gofy claim.Runner。
 *
 * 每轮 run() 做：Reap → Claim → 对每行跑一次 handler → Succeed/Fail 落库。
 * 业务只提供 handler 和两个结果钩子，不需要自己碰围栏字段。
 *
 * 注意：handler 必须幂等。租约到期但 Reap 还没跑时，慢 handler 写完数据库仍算成功；
 * 一旦行被重新认领，旧 handler 的收尾会被围栏拒绝（LEASE_LOST），此时副作用可能已经发生。
 */
final class ClaimRunner
{
    public const DEFAULT_HANDLER_TIMEOUT_SECONDS = 5.0;

    private ClaimQueue $queue;

    private Closure $handler;

    private float $handlerTimeoutSeconds;

    private int $maxAttempts;

    private Closure $onSuccess;

    private Closure $onFailure;

    public function __construct(BaseQuery $query, RunnerConfig $config)
    {
        if ($config->handler === null) {
            throw ClaimException::handlerRequired();
        }
        $this->queue = new ClaimQueue($query, $config->config);
        $this->handler = $config->handler;
        $this->handlerTimeoutSeconds = $config->handlerTimeoutSeconds > 0
            ? $config->handlerTimeoutSeconds
            : self::DEFAULT_HANDLER_TIMEOUT_SECONDS;
        $this->maxAttempts = $this->queue->maxAttempts();
        $this->onSuccess = $config->onSuccess ?? static fn (ClaimResult $result): null => null;
        $this->onFailure = $config->onFailure ?? static fn (ClaimResult $result): null => null;
    }

    /**
     * 用应用里的 DB 组件构造 Runner。$component 通常是 db。
     */
    public static function fromComponent(string $component, RunnerConfig $config): self
    {
        if ($component === '') {
            throw ClaimException::dbRequired();
        }
        if ($config->handler === null) {
            throw ClaimException::handlerRequired();
        }

        return new self(\Swoolefy\Library\Db\Facade\Db::connect($component), $config);
    }

    public function queue(): ClaimQueue
    {
        return $this->queue;
    }

    public function handlerTimeoutSeconds(): float
    {
        return $this->handlerTimeoutSeconds;
    }

    /**
     * 执行一轮轮询。由自定义进程的 tick 循环调用。
     *
     * @param string $owner 本次认领者。每个副本用不同的 owner（例如进程名 + pid）。
     * @return list<Throwable> 本轮收尾/回收的异常。LEASE_LOST 不会放进这里：
     *         重试它只会重复业务副作用，它通过 onFailure 的 ClaimResult 上报。
     */
    public function run(string $owner): array
    {
        $errors = [];
        try {
            $this->queue->reap();
        } catch (Throwable $e) {
            $errors[] = $e;
        }

        try {
            $tasks = $this->queue->claim($owner);
        } catch (Throwable $e) {
            $errors[] = $e;

            return $errors;
        }

        if ($tasks === []) {
            // 没有到期任务：用 null payload 调一次 handler 供打心跳，忽略返回值与异常。
            try {
                ($this->handler)(null);
            } catch (Throwable) {
            }

            return $errors;
        }

        foreach ($tasks as $task) {
            try {
                $this->process($task);
            } catch (Throwable $e) {
                // 一条收尾写失败不拖住批次里其余任务。
                $errors[] = $e;
            }
        }

        return $errors;
    }

    /**
     * 跑一个 handler 并记录结果。超时只约束 handler；Succeed/Fail 用调用方的协程，
     * 慢业务不会打断「记录失败」的那次写库。
     */
    private function process(ClaimTask $task): void
    {
        $started = microtime(true);
        $taskId = null;
        $handlerError = null;
        try {
            $taskId = TimeoutGuard::runWithContext(
                fn (): int|string|null => ($this->handler)($task->payload),
                $this->handlerTimeoutSeconds,
            );
        } catch (Throwable $e) {
            $handlerError = $e;
        }
        $elapsed = microtime(true) - $started;

        $leaseLost = false;
        $dead = false;
        if ($handlerError !== null) {
            try {
                $this->queue->fail($task, $handlerError);
            } catch (Throwable $e) {
                if (!ClaimException::isLeaseLost($e)) {
                    throw $e;
                }
                $leaseLost = true;
            }
            $dead = !$leaseLost && $task->attempts >= $this->maxAttempts;
        } else {
            try {
                $this->queue->succeed($task);
            } catch (Throwable $e) {
                if (!ClaimException::isLeaseLost($e)) {
                    throw $e;
                }
                $leaseLost = true;
            }
        }

        $result = new ClaimResult(
            taskId: $taskId,
            rowId: $task->id,
            job: $task->job,
            attempts: $task->attempts,
            data: $task->payload,
            error: $handlerError,
            dead: $dead,
            leaseLost: $leaseLost,
            elapsedSeconds: $elapsed,
        );
        if ($handlerError !== null || $leaseLost) {
            ($this->onFailure)($result);
        } else {
            ($this->onSuccess)($result);
        }
    }
}
