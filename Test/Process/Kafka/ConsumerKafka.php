<?php
namespace Test\Process\Kafka;

use Common\Library\Kafka\Consumer;
use Swoolefy\Core\Application;
use Swoolefy\Core\Process\AbstractProcess;

class ConsumerKafka extends AbstractProcess
{
    public function run()
    {
        /**
         * @var Consumer $consumer
         */
        $consumer = Application::getApp()->get('kafka_topic_order_group1_consumer');

        while (true) {
            $message = $consumer->consume();

            if (!empty($message)) {
                var_dump('offset=' . $message->offset . '--- err=' . $message->err . '--partition=' . $message->partition);
                switch ($message->err) {
                    case RD_KAFKA_RESP_ERR_NO_ERROR:
                        //  解释数据
                        $payload = json_decode($message->payload, true) ?? $message->payload;
                        var_dump($payload);
                        break;
                    case RD_KAFKA_RESP_ERR__PARTITION_EOF:
                        rd_kafka_err2str(RD_KAFKA_RESP_ERR__PARTITION_EOF);
                        echo "No more messages; will wait for more";
                        break;
                    case RD_KAFKA_RESP_ERR__TIMED_OUT:
                        var_dump('time out!');
                        break;
                    default:
                        var_dump("nothing");
                        break;
                }
            }
        }
    }
}