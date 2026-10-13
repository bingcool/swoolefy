<?php

namespace Test\Process\MqVerify;

use Swoolefy\Core\Sidecar\SidecarClient;
use Swoolefy\Support\Rocketmq\Dto\ReceivedMessageDto;
use Swoolefy\Support\Rocketmq\Dto\SendMessageRequestDto;
use Swoolefy\Support\Rocketmq\RocketmqConsumerProcessHandler;
use Swoolefy\Support\Rocketmq\RocketmqProducer;

/**
 * RocketMQ normal / exception / kill / deadline，都经 go-sidecar。
 * topic mq-verify（1 个队列）共用，每个场景独立消费组 + 独立 tag。
 *
 * 新消费组从最新位点开始，所以先让消费循环跑起来，10 秒后再生产。
 * deadline：invisibleMs=10000（proxy 下限）、ackSafetyMarginMs=2000，本批可用 8 秒。先发 m0（处理 3 秒），
 * 它处理期间发 m1~m3，下一批一次拉到三条；每条 4.5 秒，m3 开始时已过 9 秒，不处理也不 ack，等再投。
 */
class RocketmqVerifyConsumer extends RocketmqConsumerProcessHandler
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
        $this->topic = VerifyScenarios::ROCKETMQ_TOPIC;
        $this->consumerGroup = VerifyScenarios::rocketmqGroup($this->run, $this->scenario);
        $this->consumerTag = VerifyScenarios::rocketmqTag($this->run, $this->scenario);
        if ($this->behaviour === 'deadline') {
            $this->maxNum = 3;
            $this->invisibleMs = 10000;
            $this->ackSafetyMarginMs = 2000;
        }

        if (VerifyState::once($this->run, $this->scenario, 'produced')) {
            go(function (): void {
                \Swoole\Coroutine::sleep(10);
                try {
                    $producer = new RocketmqProducer(new SidecarClient());
                    if ($this->behaviour === 'deadline') {
                        $this->send($producer, 'm0');
                        $this->waitRecv('m0', 20);
                    }
                    foreach (['m1', 'm2', 'm3'] as $payload) {
                        $this->send($producer, $payload);
                    }
                    VerifyState::record($this->run, $this->scenario, ['e' => 'produced']);
                } catch (\Throwable $e) {
                    VerifyBehaviour::exception($this->run, $this->scenario, $e);
                }
            });
        } else {
            VerifyState::log($this->scenario . ' restarted, pid=' . getmypid());
        }

        parent::run();
    }

    public function onMessage(ReceivedMessageDto $message): void
    {
        $payload = base64_decode($message->getBodyBase64(), true);
        VerifyBehaviour::handle($this->run, $this->scenario, $this->behaviour, is_string($payload) ? $payload : '', [
            'attempt' => $message->getDeliveryAttempt(),
            'messageId' => $message->getMessageId(),
        ]);
    }

    public function onHandleException(\Throwable $throwable, array $context = [])
    {
        VerifyBehaviour::exception($this->run, $this->scenario, $throwable);
    }

    private function send(RocketmqProducer $producer, string $payload): void
    {
        $request = new SendMessageRequestDto();
        $request->setTopic(VerifyScenarios::ROCKETMQ_TOPIC);
        $request->setTag($this->consumerTag);
        $request->setKey($this->scenario . '-' . $payload);
        $request->setBodyBase64(base64_encode($payload));
        $producer->send($request, 10000);
    }

    private function waitRecv(string $payload, int $seconds): void
    {
        $deadline = time() + $seconds;
        while (time() < $deadline) {
            foreach (VerifyState::events($this->run, $this->scenario) as $event) {
                if (($event['e'] ?? '') === 'recv' && ($event['p'] ?? '') === $payload) {
                    return;
                }
            }
            \Swoole\Coroutine::sleep(0.2);
        }
    }
}
