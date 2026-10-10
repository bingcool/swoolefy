<?php
namespace Test\Process\Rocketmq;

use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Core\Sidecar\SidecarClient;
use Swoolefy\Support\Rocketmq\Dto\AckMessageRequestDto;
use Swoolefy\Support\Rocketmq\Dto\ReceiveMessageRequestDto;
use Swoolefy\Support\Rocketmq\Dto\ReceiveMessageResponseDto;
use Swoolefy\Support\Rocketmq\Dto\ReceivedMessageDto;

class ConsumerRocketmq extends AbstractProcess
{
    public function run()
    {
        $client = new SidecarClient();

        while (true) {
            try {
                \Swoole\Coroutine::sleep(1);
                $request = new ReceiveMessageRequestDto();
                $request->setTopic('test-demo');
                $request->setConsumerGroup('test-demo-group');
                $request->setConsumerTag('demo');
                $request->setMaxNum(8);
                $request->setInvisibleMs(20000);
                $response = $client->call('rocketmq', 'rocketmq.consumer.receive', $request, 25000);
                $client->assertOk($response);
                $messages = ReceiveMessageResponseDto::fromArray($response->getPayload())->getMessages();
                if ($messages === []) {
                    \Swoole\Coroutine::sleep(0.5);
                    continue;
                }
                foreach ($messages as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $message = ReceivedMessageDto::fromArray($item);
                    $payload = base64_decode($message->getBodyBase64(), true);
                    var_dump(
                        'rocketmq consume messageId=' . $message->getMessageId()
                        . ' topic=' . $message->getTopic()
                        . ' tag=' . $message->getTag()
                        . ' key=' . $message->getKey()
                        . ' attempt=' . $message->getDeliveryAttempt()
                        . ' body=' . (is_string($payload) ? $payload : '')
                    );
                    $this->ack($client, $message);
                }
            } catch (\Throwable $e) {
                var_dump('rocketmq consume failed: ' . $e->getMessage());
                \Swoole\Coroutine::sleep(1);
            }
        }
    }

    private function ack(SidecarClient $client, ReceivedMessageDto $message): void
    {
        try {
            $ack = new AckMessageRequestDto();
            $ack->setConsumerGroup('test-demo-group');
            $ack->setReceiptHandle($message->getReceiptHandle());
            $ack->setMessageId($message->getMessageId());
            $ack->setTopic($message->getTopic());
            $client->assertOk($client->call('rocketmq', 'rocketmq.consumer.ack', $ack, 3000));
            var_dump('rocketmq ack ok messageId=' . $message->getMessageId());
        } catch (\Throwable $e) {
            var_dump('rocketmq ack failed messageId=' . $message->getMessageId() . ' error=' . $e->getMessage());
        }
    }
}
