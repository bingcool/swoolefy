<?php
namespace Test\Process\Kafka;

use Common\Library\Kafka\Producer;
use Swoolefy\Core\Application;
use Swoolefy\Core\Process\AbstractProcess;


class ProducerKafka extends AbstractProcess
{
    public function run()
    {
        /**
         * @var Producer $producer
         */
        $producer = Application::getApp()->get('kafka_topic_order_group1_producer');

        while (true) {
            $producer->produce('kafka-producer:'.date('Y-m-d H:i:s'));
            \Swoole\Coroutine::sleep(0.2);
        }
    }
}