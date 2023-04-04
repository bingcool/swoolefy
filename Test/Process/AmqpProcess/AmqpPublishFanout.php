<?php
namespace Test\Process\AmqpProcess;

use Common\Library\Amqp\AmqpFanoutQueue;
use Swoolefy\Core\Application;
use Swoolefy\Core\Process\AbstractProcess;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Message\AMQPMessage;

class AmqpPublishFanout extends AbstractProcess {

    public function run()
    {
        $this->handle1();
    }

    public function handle1() {
        \Swoolefy\Core\Timer\TickManager::tickTimer(3000, function () {
            /**
             * @var AmqpFanoutQueue $amqpFanoutPublish
             */
            $amqpFanoutPublish = Application::getApp()->get('amqpOrderFanoutPublish');
            $messageBody = "fanout publish".'-'.time();
            $message = new AMQPMessage($messageBody, array('content_type' => 'text/plain', 'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT));
            $amqpFanoutPublish->publish($message);
        });
    }

    public function handle2() {
        $exchange = AmqpConst::AMQP_EXCHANGE_ROUTER_FANOUT;
        $queue = AmqpConst::AMQP_QUEUE_TOPIC;

        \Swoolefy\Core\Timer\TickManager::tickTimer(3000, function () use($exchange, $queue) {

            $connection = new AMQPStreamConnection(
                AmqpConst::AMQP_HOST,
                AmqpConst::AMQP_PORT,
                AmqpConst::AMQP_USER,
                AmqpConst::AMQP_PASS,
                AmqpConst::AMQP_VHOST
            );
            $channel = $connection->channel();

            // fanout 模式不需要指定绑定queue, 只需要投递到该交换机下，那么该交换机下的所有的队列都有分发到该消息
            // 消费者队列消费队列即可
            $channel->exchange_declare($exchange, AMQPExchangeType::FANOUT, false, true, false);

            $messageBody = "amqp fanout";
            $message = new AMQPMessage($messageBody, array('content_type' => 'text/plain'));
            $channel->basic_publish($message, $exchange);

            $channel->close();
            $connection->close();
        });
    }
}