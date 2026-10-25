<?php

declare(strict_types=1);

namespace Test\Process\MqVerify;

use Swoolefy\Support\Kafka\Dto\ReceivedMessageDto;
use Swoolefy\Support\Kafka\KafkaConsumerProcessHandler;
use Swoolefy\Support\Kafka\KafkaProducer;

/**
 * Kafka 三种提交方式 × normal / exception / kill。每个场景独立 topic、独立消费组。
 * 启动时生产 m1、m2、m3 一次（kill 重启后不再生产），然后走 KafkaConsumerProcessHandler::run()。
 */
class KafkaVerifyConsumer extends KafkaConsumerProcessHandler
{
    private string $run = '';

    private string $scenario = '';

    private string $behaviour = '';

    public function run()
    {
        $args = $this->getArgs();
        $this->run = (string) $args['run'];
        $this->scenario = (string) $args['name'];
        $this->behaviour = (string) $args['behaviour'];
        $this->commitMode = (string) $args['mode'];
        $this->topic = VerifyScenarios::kafkaTopic($this->run, $this->scenario);
        $this->consumerGroup = $this->topic;

        if (VerifyState::once($this->run, $this->scenario, 'produced')) {
            try {
                $producer = new KafkaProducer($this->topic);
                foreach (['m1', 'm2', 'm3'] as $payload) {
                    $producer->produce($payload, $payload);
                }
                VerifyState::record($this->run, $this->scenario, ['e' => 'produced']);
            } catch (\Throwable $e) {
                VerifyBehaviour::exception($this->run, $this->scenario, $e);
            }
        } else {
            VerifyState::log($this->scenario . ' restarted, pid=' . getmypid());
        }

        parent::run();
    }

    public function onMessage(ReceivedMessageDto $message): void
    {
        VerifyBehaviour::handle($this->run, $this->scenario, $this->behaviour, $message->getPayload(), [
            'offset' => $message->getOffset(),
        ]);
    }

    public function onHandleException(\Throwable $throwable, array $context = [])
    {
        VerifyBehaviour::exception($this->run, $this->scenario, $throwable);
    }
}
