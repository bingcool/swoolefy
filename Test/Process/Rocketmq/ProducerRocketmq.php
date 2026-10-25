<?php

declare(strict_types=1);

namespace Test\Process\Rocketmq;

use Swoole\Coroutine\System;
use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Core\Sidecar\SidecarClient;
use Swoolefy\Support\Rocketmq\Dto\SendMessageRequestDto;
use Swoolefy\Support\Rocketmq\RocketmqProducer;

class ProducerRocketmq extends AbstractProcess
{
    public function run()
    {
        $producer = new RocketmqProducer(new SidecarClient());

        try {
            System::sleep(1);
            $health = $producer->health(3000);
            var_dump('rocketmq health version=' . $health->getVersion() . ' listenerOk=' . (int) $health->getListenerOk());
        } catch (\Throwable $e) {
            var_dump('rocketmq health failed: ' . $e->getMessage());
        }

        while (true) {
            System::sleep(0.5);
            try {
                $body = 'rocketmq-producer:' . date('Y-m-d H:i:s');
                $request = new SendMessageRequestDto();
                $request->setTopic('test-demo');
                $request->setBodyBase64(base64_encode($body));
                $request->setTag('demo');
                $request->setKeys(['test-demo-' . bin2hex(random_bytes(4))]);
                $request->setProperties(['source' => 'producer-rocketmq']);
                $response = $producer->send($request, 10000);
                var_dump('rocketmq send messageId=' . $response->getMessageId() . ' body=' . $body);
            } catch (\Throwable $e) {
                var_dump('rocketmq send failed: ' . $e->getMessage());
            }
        }
    }
}
