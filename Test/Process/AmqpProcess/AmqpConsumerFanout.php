<?php
namespace Test\Process\AmqpProcess;

use Swoolefy\Core\Process\AbstractProcess;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exchange\AMQPExchangeType;



// fanout exchange 下，消费者需要指定queue

class AmqpConsumerFanout extends AbstractProcess
{
    public function run()
    {
        $exchange = AMQPConst::AMQP_EXCHANGE_ROUTER_FANOUT;
        $queue    = AmqpConst::AMQP_QUEUE_FANOUT;

        $consumerTag = AmqpConst::AMQP_CONSUMER_TAG;

        $connection = new AMQPStreamConnection(AmqpConst::AMQP_HOST, AmqpConst::AMQP_PORT, AmqpConst::AMQP_USER, AmqpConst::AMQP_PASS, AmqpConst::AMQP_VHOST);
        $channel = $connection->channel();

        /*
        The following code is the same both in the consumer and the producer.
        In this way we are sure we always have a queue to consume from and an
        exchange where to publish messages.
        */

        /*
        name: $queue
        passive: false 是否检测同名队列
        durable: true // the queue will survive server restarts 是否开启队列持久化
        exclusive: false // the queue can be accessed in other channels 队列是否可以被其他队列访问
        auto_delete: false //the queue won't be deleted once the channel is closed.通道关闭后是否删除队列
        */
        $channel->queue_declare($queue, false, true, false, false);

        /*
        name: $exchange
        type: direct
        passive: false
        durable: true // the exchange will survive server restarts
        auto_delete: false //the exchange won't be deleted once the channel is closed.
        */

        $channel->exchange_declare($exchange, AMQPExchangeType::FANOUT, false, true, false);

        $channel->queue_bind($queue, $exchange);


        /*
        queue: Queue from where to get the messages
        consumer_tag: Consumer identifier
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
        echo "\n--------\n";
        echo $message->body;
        echo "\n--------\n";

        //确认消息已被消费，从生产队列中移除
        $message->ack();

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

}