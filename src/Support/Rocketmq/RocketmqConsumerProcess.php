<?php

declare(strict_types=1);

namespace Swoolefy\Support\Rocketmq;

use Swoole\Coroutine\Channel;
use Swoole\Coroutine\WaitGroup;
use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Core\Sidecar\SidecarClient;
use Swoolefy\Core\Sidecar\SidecarException;
use Swoolefy\Support\Rocketmq\Dto\AckMessageRequestDto;
use Swoolefy\Support\Rocketmq\Dto\ReceiveMessageRequestDto;
use Swoolefy\Support\Rocketmq\Dto\ReceiveMessageResponseDto;
use Swoolefy\Support\Rocketmq\Dto\ReceivedMessageDto;

/**
 * 一个消费组 + Topic 一个 PHP 进程。Go 不做消费循环。
 */
final class RocketmqConsumerProcess extends AbstractProcess
{
    private bool $running = true;

    public function gracefulShutdownDrain(): void
    {
        $this->running = false;
    }

    public function run()
    {
        $cfg = $this->getExtendData();
        if (!is_array($cfg)) {
            $cfg = [];
        }
        $topic = (string) ($cfg['sidecar_rocketmq_consume_topics'] ?? '');
        $group = (string) ($cfg['sidecar_rocketmq_consumer_group'] ?? '');
        $tag = (string) ($cfg['sidecar_rocketmq_consumer_tag'] ?? '*');
        $concurrency = (int) ($cfg['sidecar_rocketmq_consumer_concurrency'] ?? 1);
        if ($concurrency < 1) {
            $concurrency = 1;
        }
        if ($concurrency > 32) {
            $concurrency = 32;
        }
        $client = new SidecarClient();
        $handler = new RocketmqMessageHandler();
        while ($this->running) {
            $req = new ReceiveMessageRequestDto();
            $req->setTopic($topic);
            $req->setConsumerGroup($group);
            $req->setConsumerTag($tag);
            $req->setMaxNum(2);
            $req->setInvisibleMs(20000);
            try {
                $response = $client->call('rocketmq', 'rocketmq.consumer.receive', $req, 25000);
                $client->assertOk($response);
            } catch (SidecarException) {
                \Swoole\Coroutine::sleep(1);
                continue;
            }
            $body = ReceiveMessageResponseDto::fromArray($response->getPayload());
            $messages = $body->getMessages();
            if ($messages === []) {
                continue;
            }
            $slots = new Channel($concurrency);
            for ($i = 0; $i < $concurrency; $i++) {
                $slots->push(1);
            }
            $wg = new WaitGroup();
            foreach ($messages as $item) {
                if (!$this->running) {
                    break;
                }
                if (!is_array($item)) {
                    continue;
                }
                $slots->pop();
                $wg->add();
                go(function () use ($slots, $wg, $item, $client, $handler, $group): void {
                    try {
                        $message = ReceivedMessageDto::fromArray($item);
                        $handler->handle($message);
                        $ack = new AckMessageRequestDto();
                        $ack->setConsumerGroup($group);
                        $ack->setReceiptHandle($message->getReceiptHandle());
                        $ack->setMessageId($message->getMessageId());
                        $ack->setTopic($message->getTopic());
                        $client->assertOk($client->call('rocketmq', 'rocketmq.consumer.ack', $ack, 3000));
                    } catch (\Throwable) {
                        // 业务失败或 ack 失败都不确认。不可见时间结束后由 RocketMQ 再投。
                    } finally {
                        $slots->push(1);
                        $wg->done();
                    }
                });
            }
            $wg->wait();
        }
    }
}
