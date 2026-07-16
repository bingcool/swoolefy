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
     * 测试 AMQP Direct 队列发布消息。
     *
     * Route: GET /api/amqp/publish
     *
     ```bash
     curl -X GET 'http://127.0.0.1:9501/api/amqp/publish' \
       -H 'Accept: application/json'
     ```
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
     * 测试 AMQP Delay Topic 队列发布消息。
     *
     * Route: GET /api/amqp/publish-delay-topic
     *
     ```bash
     curl -X GET 'http://127.0.0.1:9501/api/amqp/publish-delay-topic' \
       -H 'Accept: application/json'
     ```
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
     * 测试 AMQP Delay Direct 队列发布消息。
     *
     * Route: GET /api/amqp/publish-delay-direct
     *
     ```bash
     curl -X GET 'http://127.0.0.1:9501/api/amqp/publish-delay-direct' \
       -H 'Accept: application/json'
     ```
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
