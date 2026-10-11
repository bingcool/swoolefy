<?php
namespace Test\Process\Kafka;

use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Support\Kafka\KafkaProducer;

class ProducerKafka extends AbstractProcess
{
    public function run()
    {
        $producer = new KafkaProducer('topicOrder1');

        while (true) {
            $producer->produce('kafka-producer:' . date('Y-m-d H:i:s'));
            \Swoole\Coroutine::sleep(0.2);
        }
    }
}
