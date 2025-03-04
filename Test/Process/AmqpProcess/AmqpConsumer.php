<?php
namespace Test\Process\AmqpProcess;

use Common\Library\Amqp\AmqpDelayDirectQueue;
use Common\Library\Amqp\AmqpDirectQueue;
use Swoolefy\Core\Application;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Process\AbstractProcess;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exchange\AMQPExchangeType;

class AmqpConsumer extends AbstractProcess
{
    public function run()
    {
        $this->handle1();
    }

    public function handle1() {
        /**
         * @var AmqpDirectQueue $amqpDirect
         */
        $amqpDirect = Application::getApp()->get('orderAddDirectQueue');
        $amqpDirect->setConsumerExceptionHandler(function (\Throwable $e) {
            var_dump($e->getMessage());
        });
        //$amqpDirect->consumer([$this, 'process_message']);
        $amqpDirect->consumerWithTime([$this, 'process_message']);
    }

    public function handle3() {
        /**
         * @var AmqpDelayDirectQueue $amqpDelayDirect
         */
        $amqpDelayDirect = Application::getApp()->get('orderDelayDirectQueue');
        //$amqpDelayDirect->consumer([$this, 'process_message']);
//        $amqpDelayDirect->setConsumerExceptionHandler(function (\Throwable $e) {
//            var_dump($e->getMessage());
//        });
        $amqpDelayDirect->consumerWithTime([$this, 'process_message']);
    }

    public function handle2() {
        $exchange = AMQPConst::AMQP_EXCHANGE_ROUTER;
        $queue = AmqpConst::AMQP_QUEUE;
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

        $channel->exchange_declare($exchange, AMQPExchangeType::DIRECT, false, true, false);

        $channel->queue_bind($queue, $exchange);


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
        echo "当前时间：".date('Y-m-d H:i:s').PHP_EOL."当前进程ID：".posix_getpid().PHP_EOL;
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

    /**
     * onHandleException
     * @param \Throwable $throwable
     * @param array $context
     * @return void
     */
    public function onHandleException(\Throwable $throwable, array $context = [])
    {
        var_dump($throwable->getMessage());
    }

}