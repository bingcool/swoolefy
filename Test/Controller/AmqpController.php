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
    public function testPublish() {
        /**
         * @var AmqpAbstract $amqpDirect
         */
        $amqpDirect = Application::getApp()->get('orderAddDirectQueue');
        $messageBody = "amqp direct ".'-'.time();
        $message = new AMQPMessage($messageBody, array('content_type' => 'text/plain', 'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT));
        $amqpDirect->publish($message);
        $this->returnJson();
    }

    public function testPublish1() {
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
        $this->returnJson();
    }

    public function testPublish2() {
        /**
         * @var AmqpDelayDirectQueue $amqpDelayDirect
         */
        $amqpDelayDirect = Application::getApp()->get('orderDelayDirectQueue');
        $messageBody = "amqp delay direct ".'-'.date('Y-m-d H:i:s');
        $message = new AMQPMessage($messageBody, array('content_type' => 'text/plain', 'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT));

        $amqpDelayDirect->publish($message);
        $this->returnJson();
    }
}