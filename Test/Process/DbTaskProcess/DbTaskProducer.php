<?php

declare(strict_types=1);

namespace Test\Process\DbTaskProcess;

use Swoole\Coroutine;
use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Library\Db\Facade\Db;
use Swoolefy\Support\SupportLog;
use Throwable;

/**
 * 消费样例的生产端：每隔几秒往 claim_job_task 插一条任务。
 *
 * 真实业务不需要这个进程，直接在自己的 HTTP/Service 里调 {@see publish()} 写同一张表即可。
 * 想看 dead 路径可以传 alwaysFail=true；想看 next_run_at 延迟认领可以传 delaySeconds。
 */
final class DbTaskProducer extends AbstractProcess
{
    private bool $running = true;

    private int $sequence = 0;

    public function run()
    {
        while ($this->running) {
            try {
                $rowId = self::publish(
                    1000 + (++$this->sequence),
                    alwaysFail: $this->sequence % 5 === 0,
                    delaySeconds: 0,
                );
                SupportLog::debug('claim_demo', 'claim task published', [
                    'rowId' => $rowId,
                    'sequence' => $this->sequence,
                ]);
            } catch (Throwable $e) {
                $this->onHandleException($e);
            }

            for ($i = 0; $i < 20 && $this->running; ++$i) {
                Coroutine::sleep(0.25);
            }
        }
    }

    public function gracefulShutdownDrain(): void
    {
        $this->running = false;
    }

    /**
     * 插入一条待认领任务。必须在有 Application 的协程里调用（自定义进程 / HTTP 请求）。
     *
     * @return int claim 表主键
     */
    public static function publish(int $taskId, bool $alwaysFail = false, int $delaySeconds = 0): int
    {
        $query = Db::connect(DbTaskConsumer::DB_COMPONENT)
            ->table(DbTaskConsumer::TABLE)
            // claim 表没有 tenant_id，别让全局租户拦截器改写这条 INSERT。
            ->setOption('without_tenant_scope', true);

        return (int) $query->insertGetId([
            'job_type' => DbTaskConsumer::JOB,
            'status' => 0,
            'owner' => '',
            'attempts' => 0,
            'next_run_at' => $delaySeconds > 0 ? date('Y-m-d H:i:s', time() + $delaySeconds) : null,
            'payload' => json_encode([
                'taskId' => $taskId,
                'alwaysFail' => $alwaysFail,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_by' => 'claim-demo',
        ]);
    }
}
