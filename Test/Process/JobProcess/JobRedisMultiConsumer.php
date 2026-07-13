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

namespace Test\Process\JobProcess;

use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Support\Job\JobComponentFactory;
use Swoolefy\Support\Job\JobEnvelope;
use Test\App;
use Test\Module\Job\OrderExportHandler;
use Test\Module\Job\OrderPaidNotifyHandler;

/**
 * 基于 {@see JobHandlerRegistry} 的多 jobType Redis 消费 Demo。
 *
 * Event.php：
 *   ProcessManager::getInstance()->addProcess(
 *       'job-redis-multi',
 *       JobRedisMultiConsumer::class,
 *       true, [], null, true,
 *   );
 */
final class JobRedisMultiConsumer extends AbstractProcess
{
    public function run()
    {
        // 多 Handler 共进程：按 jobType 路由
        $registry = JobComponentFactory::registry(
            new OrderPaidNotifyHandler(),
            new OrderExportHandler(),
        );
        $runner = JobComponentFactory::runner();
        $redis = App::getRedis();
        $redisObj = is_object($redis) && method_exists($redis, 'getObject') ? $redis->getObject() : $redis;
        $dlq = JobComponentFactory::redisDeadLetter($redisObj);

        while (true) {
            try {
                if ($this->getCurrentRunCoroutineNum() > 20) {
                    \Swoole\Coroutine::sleep(0.05);
                    continue;
                }

                $queue = App::getQueue();
                // 重试次数由 Job 层掌管；传输层预算设高
                $queue->setRetryTimes(32);
                $result = $queue->pop(3);
                if (empty($result)) {
                    continue;
                }

                $raw = $result[1] ?? null;
                if (is_string($raw)) {
                    $decoded = json_decode($raw, true);
                    $raw = is_array($decoded) ? $decoded : null;
                }
                if (!is_array($raw)) {
                    continue;
                }

                // 多类型消费者要求标准信封；非法结构直接跳过
                $job = JobEnvelope::fromArray($raw);
                goApp(function () use ($runner, $registry, $job, $queue, $dlq) {
                    $runner->runRegistered(
                        $registry,
                        $job,
                        requeue: static function (JobEnvelope $next, int $delayMs) use ($queue): void {
                            // ms → 秒（Queue::retry）
                            $seconds = max(1, (int) ceil($delayMs / 1000));
                            $queue->retry($next->toArray(), $seconds);
                        },
                        dead: static function (JobEnvelope $failed, string $error) use ($dlq): void {
                            $dlq->push($failed, $error, 'default');
                        },
                    );
                });
            } catch (\Throwable $e) {
                $this->onHandleException($e);
                \Swoole\Coroutine::sleep(1);
            }
        }
    }
}
