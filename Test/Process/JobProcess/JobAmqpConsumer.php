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

use Swoolefy\Core\Application;
use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Library\Amqp\AmqpDirectQueue;
use Swoolefy\Support\Job\JobComponentFactory;
use Swoolefy\Support\Job\JobEnvelope;
use Test\Module\Job\OrderExportHandler;
use Test\Module\Job\OrderPaidNotifyHandler;

/**
 * AMQP Job 信封 + Registry 消费 Demo。
 *
 * Event.php：
 *   ProcessManager::getInstance()->addProcess(
 *       'job-amqp-consumer',
 *       JobAmqpConsumer::class,
 *       true, [], null, true,
 *   );
 *
 * 将信封 JSON 发布到 orderAddDirectQueue（与 AmqpController Demo 相同）。
 */
final class JobAmqpConsumer extends AbstractProcess
{
    public function run()
    {
        $registry = JobComponentFactory::registry(
            new OrderPaidNotifyHandler(),
            new OrderExportHandler(),
        );
        $runner = JobComponentFactory::runner();

        /** @var AmqpDirectQueue $amqp */
        $amqp = Application::getApp()->get('orderAddDirectQueue');
        $amqp->setConsumerExceptionHandler(function (\Throwable $e) {
            $this->onHandleException($e);
        });

        $amqp->consumerWithTime(function ($message) use ($runner, $registry, $amqp) {
            $body = $message->body ?? '';
            $data = json_decode((string) $body, true);
            if (!is_array($data)) {
                // 非 JSON：ack 丢掉，避免反复投递
                $message->ack();
                return;
            }

            try {
                $job = JobEnvelope::fromArray($data);
            } catch (\Throwable $e) {
                // 非法信封 → ack，避免毒消息死循环；异常走 onHandleException
                $this->onHandleException($e);
                $message->ack();
                return;
            }

            $runner->runRegistered(
                $registry,
                $job,
                requeue: function (JobEnvelope $next, int $delayMs) use ($amqp, $message) {
                    // 优先用已有延迟队列组件；否则直接 republish
                    try {
                        $delay = Application::getApp()->get('orderDelayDirectQueue');
                        if (is_object($delay) && method_exists($delay, 'publish')) {
                            $delay->publish(json_encode($next->toArray(), JSON_UNESCAPED_UNICODE));
                        } else {
                            $amqp->publish(json_encode($next->toArray(), JSON_UNESCAPED_UNICODE));
                        }
                    } catch (\Throwable) {
                        $amqp->publish(json_encode($next->toArray(), JSON_UNESCAPED_UNICODE));
                    }
                    // delayMs 留给 delay 队列 TTL/插件；本 demo 未映射
                    unset($delayMs);
                    // 原消息必须 ack，否则会重复投递
                    $message->ack();
                },
                dead: function (JobEnvelope $failed, string $error) use ($message) {
                    // 零建表：若已配置 AMQP DLX 则依赖拓扑；毒消息始终 ack
                    unset($failed, $error);
                    $message->ack();
                },
            );

            // SUCCESS / DISCARD / 重试或死信路径已 ack
            try {
                $message->ack();
            } catch (\Throwable) {
                // requeue/dead 时可能已 ack
            }
        });
    }
}
