<?php
namespace Test\Process\AmqpProcess;

use Common\Library\Amqp\AmqpDelayTopicQueue;
use Common\Library\Amqp\AmqpTopicQueue;
use Swoolefy\Core\Application;
use Swoolefy\Core\Process\AbstractProcess;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Message\AMQPMessage;

class AmqpPublishTopic extends AbstractProcess {

    public function init()
    {
        // 4.8 版本对于sockets的hook不完美，需要关闭
        \Swoole\Runtime::setHookFlags(SWOOLE_HOOK_SOCKETS ^ SWOOLE_HOOK_SOCKETS);
        parent::init();
    }

    public function run()
    {
        $this->handle3();
    }

    public function handle1() {
        \Swoolefy\Core\Timer\TickManager::afterTimer(5000, function () {
            /**
             * @var AmqpTopicQueue $amqpTopicPublish
             */
            $amqpTopicPublish = Application::getApp()->get('orderAddTopicQueue');
            $messageBody = "amqp topic ".'-'.date("Y-m-d H:i:s");
            $message = new AMQPMessage(
                $messageBody,
                array(
                    'content_type' => 'text/plain',
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT
                )
            );
            $amqpTopicPublish->publish($message, 'orderSaveEvent.send');
        });
    }

    /**
     * topic 模式的延迟队列
     * @return void
     */
    public function handle3() {
        \Swoolefy\Core\Timer\TickManager::afterTimer(5000, function () {
            /**
             * @var AmqpDelayTopicQueue $amqpDelayTopicPublish
             */
            $amqpDelayTopicPublish = Application::getApp()->get('orderDelayTopicQueue');
            $messageBody = "amqp delay topic ".'-'.date("Y-m-d H:i:s");
            $message = new AMQPMessage(
                $messageBody,
                array(
                    'content_type' => 'text/plain',
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                    //那如果我即给单条消息设置了时间expiration, 又给队列设置了时间x-message-ttl会怎么样?
                    // 如果两者 都设置的话，则根据最短的那个时间来
                    'expiration' => 20000
                )
            );
            $amqpDelayTopicPublish->publish($message, 'orderSaveEvent2.send');

//            $messageBody = "amqp delay delay delay delay delay topic ".'-'.date('Y-m-d H:i:s');
//            $message = new AMQPMessage(
//                $messageBody,
//                array(
//                    'content_type' => 'text/plain',
//                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
//                    'expiration' => 20000
//                )
//            );
//            $amqpDelayTopicPublish->publish($message, 'orderSaveEvent2.sms');
        });
    }

    public function handle2() {
        $exchange = AmqpConst::AMQP_EXCHANGE_ROUTER_TOPIC;
        $queue = AmqpConst::AMQP_QUEUE_TOPIC;

        \Swoolefy\Core\Timer\TickManager::afterTimer(5000, function () use($exchange, $queue) {

            $connection = new AMQPStreamConnection(
                AmqpConst::AMQP_HOST,
                AmqpConst::AMQP_PORT,
                AmqpConst::AMQP_USER,
                AmqpConst::AMQP_PASS,
                AmqpConst::AMQP_VHOST
            );
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

            $channel->exchange_declare(
                $exchange,
                AMQPExchangeType::TOPIC, // topic 组播模式
                false,
                true,
                false
            );

            // 匹配以orderSaveEvent开头的routing key,全部会进入到该队列，所以可以某个模块根据routing key的不同来定义不同的queue
            $channel->queue_bind($queue, $exchange, 'orderSaveEvent.#');

            $messageBody = "topic amqp";
            $message = new AMQPMessage($messageBody, array('content_type' => 'text/plain', 'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT));

            // routing key 别忘了
            $channel->basic_publish($message, $exchange,'orderSaveEvent.send');

            // routing key 别忘了
            $messageBody = "topic amqp other routing key";
            $message = new AMQPMessage($messageBody, array('content_type' => 'text/plain', 'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT));

            $channel->basic_publish($message, $exchange,'orderSaveEvent.aa');

            $channel->close();
            $connection->close();
        });
    }
}