<?php
namespace Test\Controller;

use Swoolefy\Core\Controller\BController;
use Test\Factory;

class QueueController extends BController
{
    public function push()
    {
        Factory::getQueue()->push(['id' => rand(1,1000)]);
        $this->returnJson(['id' => rand(1, 2)]);
    }
}