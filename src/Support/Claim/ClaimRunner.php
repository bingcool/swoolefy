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
 * 每轮 run() 做：Reap → Claim → 逐行 Extend 续租 → 跑 handler → Succeed/Fail 落库。
 * 业务只提供 handler 和两个结果钩子，不需要自己碰围栏字段。
 *
 * 注意：handler 必须幂等。Extend 只覆盖排队窗口；handler 跑过租约仍可能在执行途中被
 * 接管并再跑一遍。租约到期但 Reap 还没跑时，慢 handler 写完数据库仍算成功；一旦行被
 * 重新认领，旧 handler 的收尾会被围栏拒绝（LEASE_LOST），此时副作用可能已经发生。
 */
final class ClaimRunner
{
    /**
     * handler 的协作式截止时间，不是强杀：它只能中断可协作的 Swoole 协程调用，
     * 阻塞型扩展要自己设网络超时。必须小于租约，构造时会强制校验。
     */
    public const DEFAULT_HANDLER_TIMEOUT_SECONDS = 5.0;

    private ClaimQueue $queue;

    private Closure $handler;

    private float $handlerTimeoutSeconds;

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
        // 超时必须短于租约：Extend 只在 handler 开始前续租，允许活过租约的 handler
        // 会在执行中被回收、被另一个副本再跑一遍——Extend 帮不上忙，因为它只在
        // handler 开始前执行。
        if ($this->handlerTimeoutSeconds >= $this->queue->leaseSeconds()) {
            throw ClaimException::handlerTimeoutExceedsLease(
                $this->handlerTimeoutSeconds,
                $this->queue->leaseSeconds(),
            );
        }
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
        // 执行 handler 前立刻重新武装本行租约。claim() 给整批行同一个截止时间，
        // 靠后的行可能已经等过期。extend() 给一个新窗口，并通过 CAS 告诉我们是否
        // 已被其它副本回收；若是则跳过 handler——现在再跑只会和新 owner 的副作用重复。
        //
        // 它保护的是排队窗口，不是执行窗口：handler 一旦开始跑，租约在其脚下过期仍
        // 会被回收、行可能在别处再跑一遍。所以租约必须大于 handler 超时（构造时强制），
        // handler 必须幂等。
        try {
            $this->queue->extend($task);
        } catch (Throwable $e) {
            if (!ClaimException::isLeaseLost($e)) {
                throw $e;
            }
            ($this->onFailure)(new ClaimResult(
                taskId: null,
                rowId: $task->id,
                job: $task->job,
                attempts: $task->attempts,
                data: $task->payload,
                error: $e,
                leaseLost: true,
            ));

            return;
        }

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
                // fail() 的 dead 返回值才是唯一正确的答案：次数耗尽与 rerun_allowed = 0
                // 都会置死，只看 attempts 看不出后者。
                $dead = $this->queue->fail($task, $handlerError);
            } catch (Throwable $e) {
                if (!ClaimException::isLeaseLost($e)) {
                    throw $e;
                }
                $leaseLost = true;
            }
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
