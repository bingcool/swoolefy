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
use Swoolefy\Library\Kafka\Consumer;
use Swoolefy\Support\Job\JobComponentFactory;
use Swoolefy\Support\Job\JobEnvelope;
use Test\Module\Job\OrderExportHandler;
use Test\Module\Job\OrderPaidNotifyHandler;

/**
 * Kafka Job 信封 + Registry 消费 Demo。
 *
 * Event.php：
 *   ProcessManager::getInstance()->addProcess(
 *       'job-kafka-consumer',
 *       JobKafkaConsumer::class,
 *       true, [], null, true,
 *   );
 *
 * 重试：有 producer 组件则 republish；否则仅依赖监控（log-only dead）。
 * 重试时不阻塞分区提交。
 */
final class JobKafkaConsumer extends AbstractProcess
{
    public function run()
    {
        $registry = JobComponentFactory::registry(
            new OrderPaidNotifyHandler(),
            new OrderExportHandler(),
        );
        $runner = JobComponentFactory::runner();

        /** @var Consumer $consumer */
        $consumer = Application::getApp()->get('kafka_topic_order_group1_consumer');

        while (true) {
            try {
                $message = $consumer->consume();
                if (empty($message)) {
                    continue;
                }

                // 仅处理成功拉取的消息；错误码跳过（由 librdkafka 重试/心跳）
                if (!defined('RD_KAFKA_RESP_ERR_NO_ERROR') || $message->err !== RD_KAFKA_RESP_ERR_NO_ERROR) {
                    continue;
                }

                $data = json_decode((string) $message->payload, true);
                if (!is_array($data)) {
                    continue;
                }

                $job = JobEnvelope::fromArray($data);
                $runner->runRegistered(
                    $registry,
                    $job,
                    requeue: static function (JobEnvelope $next, int $delayMs): void {
                        // Kafka 无原生 delay：立即 republish；delayMs 未使用
                        unset($delayMs);
                        try {
                            $producer = Application::getApp()->get('kafka_topic_order_producer');
                            if (is_object($producer) && method_exists($producer, 'produce')) {
                                $producer->produce(json_encode($next->toArray(), JSON_UNESCAPED_UNICODE));
                            }
                        } catch (\Throwable) {
                            // 无 producer：消息已消费，依赖监控告警
                        }
                    },
                    dead: static function (JobEnvelope $failed, string $error): void {
                        // Demo：log-only 死信；生产可写 RedisDeadLetter 或旁路 topic
                        unset($failed, $error);
                    },
                );
            } catch (\Throwable $e) {
                $this->onHandleException($e);
                \Swoole\Coroutine::sleep(1);
            }
        }
    }
}
