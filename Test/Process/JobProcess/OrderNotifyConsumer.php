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
use Swoolefy\Support\Job\JobEnvelope;
use Swoolefy\Support\Job\JobRetryPolicy;
use Swoolefy\Support\Job\JobRunner;
use Test\App;
use Test\Module\Job\OrderPaidNotifyHandler;

/**
 * Redis List 队列上的 Job 消费 Demo（沿用现有 ProcessManager 写法）。
 *
 * 在 Event.php 注册：
 *   ProcessManager::getInstance()->addProcess(
 *       'job-order-notify',
 *       OrderNotifyConsumer::class,
 *       true, [], null, true,
 *   );
 *
 * 生产侧：
 *   (new JobPublisher(fn ($d) => App::getQueue()->push($d)))
 *       ->dispatch('order.paid.notify', ['orderId' => 1], ['tenantId' => 't1']);
 */
final class OrderNotifyConsumer extends AbstractProcess
{
    public const DEAD_LETTER_KEY = 'job:dead:default';

    public function run()
    {
        $handler = new OrderPaidNotifyHandler();
        $runner = new JobRunner(new JobRetryPolicy(
            maxAttempts: 5,
            baseDelayMs: 1000,
            backoffMultiplier: 2.0,
            jitterRatio: 0.1,
        ));

        while (true) {
            try {
                // 限制并发协程数，避免瞬时打满下游
                if ($this->getCurrentRunCoroutineNum() > 20) {
                    \Swoole\Coroutine::sleep(0.05);
                    continue;
                }

                $queue = App::getQueue();
                // 重试次数由 Job 层掌管；传输层重试预算设高避免抢先耗尽
                $queue->setRetryTimes(32);

                $result = $queue->pop(3);
                if (empty($result)) {
                    continue;
                }

                // Queue::pop 返回 [score?, payload] 形式；payload 可能是 JSON 字符串
                $raw = $result[1] ?? null;
                if (is_string($raw)) {
                    $decoded = json_decode($raw, true);
                    $raw = is_array($decoded) ? $decoded : ['value' => $raw];
                }
                if (!is_array($raw)) {
                    continue;
                }

                // 兼容旧消息：非信封也能包装后处理
                $job = JobEnvelope::wrapLegacy($raw, 'order.paid.notify');
                if ($job->jobType !== 'order.paid.notify') {
                    // 已 pop：类型不匹配则推回，留给其他消费者 / 人工排查
                    $queue->push($job->toArray());
                    continue;
                }

                goApp(function () use ($runner, $handler, $job, $queue) {
                    $runner->run(
                        $handler,
                        $job,
                        requeue: static function (JobEnvelope $next, int $delayMs) use ($queue): void {
                            // Job 层 delay 为 ms；Queue::retry 单位为秒
                            $seconds = max(1, (int) ceil($delayMs / 1000));
                            $queue->retry($next->toArray(), $seconds);
                        },
                        dead: static function (JobEnvelope $failed, string $error) use ($queue): void {
                            // 零建表死信：写入 Redis List
                            $redis = $queue->getRedis();
                            $payload = json_encode([
                                'job' => $failed->toArray(),
                                'error' => $error,
                                'at' => time(),
                            ], JSON_UNESCAPED_UNICODE);
                            $redis->lPush(self::DEAD_LETTER_KEY, $payload);
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
