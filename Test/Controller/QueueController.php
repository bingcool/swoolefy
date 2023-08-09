<?php
namespace Test\Controller;

use Common\Library\Queues\Queue;
use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;

class QueueController extends BController
{
    public function push()
    {
        /**
         * @var Queue $queue
         */
        $queue = Application::getApp()->get('queue');
        $queue->push();
        $this->returnJson(['id' => rand(1, 2)]);
    }
}