<?php
namespace Test\Controller;

use Common\Library\Amqp\AmqpAbstract;
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
}