<?php

declare(strict_types=1);

namespace Test\Process\DbTaskProcess;

use Swoole\Coroutine;
use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Support\Claim\ClaimConfig;
use Swoolefy\Support\Claim\ClaimHooks;
use Swoolefy\Support\Claim\ClaimResult;
use Swoolefy\Support\Claim\ClaimRunner;
use Swoolefy\Support\Claim\RunnerConfig;
use Swoolefy\Support\SupportLog;
use Throwable;

/**
 * 数据库行级认领消费样例。
 *
 * 从 claim_job_task 扫描到期的 demo.order.notify 行，用 FOR UPDATE SKIP LOCKED 抢行。
 * 可以开多个进程（Test/Event.php 注册了两个），它们扫同一张表而不会重复处理同一行。
 * Runner 在每行 handler 执行前会先续租（Extend），所以批次里靠后的行不会因为排队过久
 * 被别的副本回收。
 *
 * 需要 MySQL 8 与建表语句：src/Support/Claim/claim.sql。
 * 用 TEST_DB_TASK=1 启动服务才会注册，见 Test/Event.php::onStartRegisterProcess()。
 */
final class DbTaskConsumer extends AbstractProcess
{
    public const JOB = 'demo.order.notify';

    public const TABLE = 'claim_job_task';

    public const DB_COMPONENT = 'db';

    private bool $running = true;

    private ?ClaimRunner $runner = null;

    public function run()
    {
        while ($this->running) {
            try {
                $errors = $this->runner()->run($this->owner());
                foreach ($errors as $error) {
                    SupportLog::error('claim_demo', 'claim round failed', [
                        'owner' => $this->owner(),
                        'error' => $error->getMessage(),
                    ]);
                }
            } catch (Throwable $e) {
                $this->onHandleException($e);
                Coroutine::sleep(1);
                continue;
            }

            // 没有到期任务时内层也会调一次 handler；空转退避交给这里统一控制。
            Coroutine::sleep(0.5);
        }
    }

    /**
     * SIGTERM 时停止下一轮扫描。正在跑的 handler 会在租约到期后由 Reap 回收，
     * 因此 handler 必须幂等。
     */
    public function gracefulShutdownDrain(): void
    {
        $this->running = false;
    }

    /**
     * owner 要能区分副本：同一个 owner 反复认领同一行时，只有 attempts 能挡住旧写回。
     */
    private function owner(): string
    {
        return $this->getProcessName() . '#' . posix_getpid();
    }

    private function runner(): ClaimRunner
    {
        if ($this->runner !== null) {
            return $this->runner;
        }

        $handler = new DbTaskHandler();
        $hooks = new ClaimHooks(
            onClaim: static fn (string $job, int $claimed): null => null,
            onReap: static function (string $job, int $reaped, int $dead): void {
                if ($reaped > 0 || $dead > 0) {
                    SupportLog::info('claim_demo', 'claim reap', [
                        'job' => $job, 'reaped' => $reaped, 'dead' => $dead,
                    ]);
                }
            },
            onLeaseLost: static function (string $job): void {
                SupportLog::warning('claim_demo', 'claim lease lost', ['job' => $job]);
            },
        );

        $this->runner = ClaimRunner::fromComponent(self::DB_COMPONENT, new RunnerConfig(
            config: new ClaimConfig(
                table: self::TABLE,
                job: self::JOB,
                batch: 10,
                leaseSeconds: 30,
                maxAttempts: 5,
                backoffSeconds: 2,
                maxBackoffSeconds: 30,
                hooks: $hooks,
            ),
            // 超时要远小于租约，否则 handler 还没结束行就可能被别的副本接管。
            handlerTimeoutSeconds: 5.0,
            handler: $handler->handle(...),
            onSuccess: static function (ClaimResult $result): void {
                SupportLog::debug('claim_demo', 'claim task done', [
                    'taskId' => $result->taskId,
                    'rowId' => $result->rowId,
                    'attempts' => $result->attempts,
                    'elapsed' => round($result->elapsedSeconds, 3),
                ]);
            },
            onFailure: static function (ClaimResult $result): void {
                SupportLog::warning('claim_demo', 'claim task failed', [
                    'taskId' => $result->taskId,
                    'rowId' => $result->rowId,
                    'attempts' => $result->attempts,
                    'dead' => $result->dead,
                    'leaseLost' => $result->leaseLost,
                    'error' => $result->error?->getMessage(),
                ]);
            },
        ));

        return $this->runner;
    }
}
