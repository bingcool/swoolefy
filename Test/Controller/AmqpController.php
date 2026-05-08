<?php
namespace Test\Controller;

use Common\Library\Amqp\AmqpAbstract;
use Common\Library\Amqp\AmqpDelayDirectQueue;
use Common\Library\Amqp\AmqpDelayTopicQueue;
use PhpAmqpLib\Message\AMQPMessage;
use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;

class AmqpController extends BController
{
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

    public function testPublish2(): bool {
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