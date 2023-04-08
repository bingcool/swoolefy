<?php
namespace Test\Process\AmqpProcess;

use Common\Library\Amqp\AmqpDelayTopicQueue;
use Common\Library\Amqp\AmqpTopicQueue;
use Swoolefy\Core\Application;
use Swoolefy\Core\Process\AbstractProcess;
use PhpAmqpLib\Connection\AMQPStreamConnection;

class AmqpConsumerTopic extends AbstractProcess
{
    public function run()
    {
        $this->handle3();
    }

    public function handle1() {
        /**
         * @var AmqpTopicQueue $amqpTopicConsumer
         */
        $amqpTopicConsumer = Application::getApp()->get('orderAddTopicQueue');
        $amqpTopicConsumer->consumer([$this, 'process_message']);
        //$amqpTopicConsumer->consumerWithTime([$this, 'process_message']);
    }

    public function handle3() {
        /**
         * @var AmqpDelayTopicQueue $amqpDelayTopicConsumer
         */
        $amqpDelayTopicConsumer = Application::getApp()->get('orderDelayTopicQueue');
        //$amqpTopicConsumer->consumer([$this, 'process_message']);

        $amqpDelayTopicConsumer->consumerWithTime([$this, 'process_message']);
    }


    public function handle2() {
        $exchange = AMQPConst::AMQP_EXCHANGE_ROUTER_TOPIC;
        $queue = AmqpConst::AMQP_QUEUE_TOPIC;
        $consumerTag = AmqpConst::AMQP_CONSUMER_TAG;

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
        queue: Queue from where to get the messages
        consumer_tag: ConsumerKafka identifier
        no_local: Don't receive messages published by this consumer.
        no_ack: If set to true, automatic acknowledgement mode will be used by this consumer. See https://www.rabbitmq.com/confirms.html for details.
        exclusive: Request exclusive consumer access, meaning only this consumer can access the queue
        nowait:
        callback: A PHP Callback
        */

        register_shutdown_function([$this, 'shutdown'], $channel, $connection);

        $channel->basic_consume($queue, $consumerTag, false, false, false, false, [$this, 'process_message']);

        // Loop as long as the channel has callbacks registered
        $channel->consume();
    }


    /**
     * @param \PhpAmqpLib\Message\AMQPMessage $message
     */
    public function process_message($message)
    {
        $message->ack();
        // 匹配出不同的route key 做不通的处理，类似策略模式
        switch ($message->getRoutingKey()) {
            case 'orderSaveEvent.send':
                    echo "当前时间：".date('Y-m-d H:i:s')."\n";
                    var_dump($message->getBody(), $message->getRoutingKey(),$message->getConsumerTag());
                    echo "---------\n";
                break;
            case 'orderSaveEvent.aa':
                echo "当前时间：".date('Y-m-d H:i:s')."\n";
                    var_dump($message->getBody(), $message->getRoutingKey());
                    echo "---------\n";
                break;
            default:
                echo "当前时间default：".date('Y-m-d H:i:s')."\n";
                var_dump($message->getBody(), $message->getRoutingKey());
                echo "---------\n";
                break;
        }

// Send a message with the string "quit" to cancel the consumer.
        if ($message->body === 'quit') {
            $message->getChannel()->basic_cancel($message->getConsumerTag());
        }
    }

    /**
     * @param \PhpAmqpLib\Channel\AMQPChannel $channel
     * @param \PhpAmqpLib\Connection\AbstractConnection $connection
     */
    public function shutdown($channel, $connection)
    {
        $channel->close();
        $connection->close();
    }

    public function onShutDown()
    {
        parent::onShutDown();
    }

}