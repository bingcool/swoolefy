<?php
namespace Test\Process\AmqpProcess;

use Common\Library\Amqp\AmqpAbstract;
use Common\Library\Amqp\AmqpDelayDirectQueue;
use Common\Library\Amqp\AmqpDirectQueue;
use Swoolefy\Core\Application;
use Swoolefy\Core\Process\AbstractProcess;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Message\AMQPMessage;

class AmqpPublish extends AbstractProcess {

    public function run()
    {
        $this->handle1();
    }

    public function handle1() {
        \Swoolefy\Core\Timer\TickManager::tickTimer(3000, function () {
            /**
             * @var AmqpDirectQueue $amqpDirect
             */
            $amqpDirect = Application::getApp()->get('orderAddDirectQueue');
            $messageBody = "amqp direct ".'-'.time();
            $message = new AMQPMessage($messageBody, array('content_type' => 'text/plain', 'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT));
            $amqpDirect->publish($message);
        });
    }

    /**
     * 延迟队列
     * @return void
     */
    public function handle3() {
        \Swoolefy\Core\Timer\TickManager::tickTimer(3000, function () {
            /**
             * @var AmqpDelayDirectQueue $amqpDelayDirect
             */
            $amqpDelayDirect = Application::getApp()->get('orderDelayDirectQueue');

            $messages = [
                ['body' => date('Y-m-d H:i:s').'-Low priority message-1', 'priority' => 1, 'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT],
//                ['body' => 'Medium priority message-5', 'priority' => 5, 'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT],
                //['body' => date('Y-m-d H:i:s').'High priority message-10', 'priority' => 10, 'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]
            ];

            foreach ($messages as $msgData) {
                $message = new AMQPMessage(
                    $msgData['body'],
                    [
                        'content_type' => 'text/plain',
                        'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                        'priority' => $msgData['priority']
                    ]);
                $amqpDelayDirect->publish($message);
            }
        });
    }

    public function handle2() {
        $exchange = AmqpConst::AMQP_EXCHANGE_ROUTER;
        $queue = AmqpConst::AMQP_QUEUE;

        \Swoolefy\Core\Timer\TickManager::tickTimer(3000, function () use($exchange, $queue) {

            $connection = new AMQPStreamConnection(AmqpConst::AMQP_HOST,AmqpConst::AMQP_PORT, AmqpConst::AMQP_USER, AmqpConst::AMQP_PASS, AmqpConst::AMQP_VHOST);
            $channel = $connection->channel();

            /*
                The following code is the same both in the consumer and the producer.
                In this way we are sure we always have a queue to consume from and an
                    exchange where to publish messages.
            */

            /*
                name: $queue
                passive: false
                durable: true // the queue will survive server restarts
                exclusive: false // the queue can be accessed in other channels
                auto_delete: false //the queue won't be deleted once the channel is closed.
            */
            $channel->queue_declare($queue, false, true, false, false);

            /*
                name: $exchange
                type: direct
                passive: false
                durable: true // the exchange will survive server restarts
                auto_delete: false //the exchange won't be deleted once the channel is closed.
            */

            $channel->exchange_declare($exchange, AMQPExchangeType::DIRECT, false, true, false);

            $channel->queue_bind($queue, $exchange);

            $messageBody = "name phone";
            $message = new AMQPMessage($messageBody, array('content_type' => 'text/plain', 'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT));
            $channel->basic_publish($message, $exchange);

            $channel->close();
            $connection->close();
        });
    }
}