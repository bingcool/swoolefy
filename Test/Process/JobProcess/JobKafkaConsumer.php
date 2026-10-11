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

use Swoolefy\Support\Job\JobComponentFactory;
use Swoolefy\Support\Job\JobEnvelope;
use Swoolefy\Support\Kafka\Dto\ReceivedMessageDto;
use Swoolefy\Support\Kafka\KafkaConsumerProcessHandler;
use Swoolefy\Support\Kafka\KafkaProducer;
use Test\Module\Job\OrderExportHandler;
use Test\Module\Job\OrderPaidNotifyHandler;

/**
 * Kafka Job 信封消费。继承消费进程后只处理 onMessage。
 * 重试时用 KafkaProducer 再发到同一 Topic。Kafka 没有原生延迟。
 */
final class JobKafkaConsumer extends KafkaConsumerProcessHandler
{
    protected string $topic = 'topicOrder1';

    protected string $consumerGroup = 'order_group1';

    public function onMessage(ReceivedMessageDto $message): void
    {
        $data = json_decode($message->getPayload(), true);
        if (!is_array($data)) {
            return;
        }

        $registry = JobComponentFactory::registry(
            new OrderPaidNotifyHandler(),
            new OrderExportHandler(),
        );
        $runner = JobComponentFactory::runner();
        $topic = $this->topic;
        $runner->runRegistered(
            $registry,
            JobEnvelope::fromArray($data),
            requeue: static function (JobEnvelope $next, int $delayMs) use ($topic): void {
                unset($delayMs);
                (new KafkaProducer($topic))->produce(json_encode($next->toArray(), JSON_UNESCAPED_UNICODE));
            },
            dead: static function (JobEnvelope $failed, string $error): void {
                unset($failed, $error);
            },
        );
    }
}
