<?php
namespace Test\Controller;

use Swoolefy\Annotation\ApiOperation;
use Swoolefy\Library\Amqp\AmqpAbstract;
use Swoolefy\Library\Amqp\AmqpDelayDirectQueue;
use Swoolefy\Library\Amqp\AmqpDelayTopicQueue;
use PhpAmqpLib\Message\AMQPMessage;
use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;

class AmqpController extends BController
{
    /**
     * @Api 测试 AMQP Direct 队列发布消息
     *
     * curl -X GET 'http://127.0.0.1:9501/api/amqp/publish'
     */
    #[ApiOperation(description: '测试 AMQP Direct 队列发布消息')]
    public function testPublish(): bool
    {
        /**
         * @var AmqpAbstract $amqpDirect
         */
        $amqpDirect = Application::getApp()->get('orderAddDirectQueue');
        $messageBody = "amqp direct ".'-'.time();
        $message = new AMQPMessage($messageBody, array('content_type' => 'text/plain', 'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT));
        $amqpDirect->publish($message);
        return true;
    }

    /**
     * @Api 测试 AMQP Delay Topic 队列发布消息
     *
     * curl -X GET 'http://127.0.0.1:9501/api/amqp/publish-delay-topic'
     */
    #[ApiOperation(description: '测试 AMQP Delay Topic 队列发布消息')]
    public function testPublish1(): bool
    {
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
                'expiration' => 20000
            )
        );

        $amqpDelayTopicPublish->publish($message, 'orderSaveEvent.send');
        return true;
    }

    /**
     * @Api 测试 AMQP Delay Direct 队列发布消息
     *
     * curl -X GET 'http://127.0.0.1:9501/api/amqp/publish-delay-direct'
     */
    #[ApiOperation(description: '测试 AMQP Delay Direct 队列发布消息')]
    public function testPublish2(): bool
    {
        /**
         * @var AmqpDelayDirectQueue $amqpDelayDirect
         */
        $amqpDelayDirect = Application::getApp()->get('orderDelayDirectQueue');
        $messageBody = "amqp delay direct ".'-'.date('Y-m-d H:i:s');
        $message = new AMQPMessage($messageBody, array('content_type' => 'text/plain', 'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT));

        $amqpDelayDirect->publish($message);
        return true;
    }
}
