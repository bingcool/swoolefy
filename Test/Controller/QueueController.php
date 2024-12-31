<?php
namespace Test\Controller;

use Swoolefy\Core\Controller\BController;
use Test\App;

class QueueController extends BController
{
    public function push()
    {
        App::getQueue()->push(['id' => rand(1,1000)]);
        $this->returnJson(['id' => rand(1, 2)]);
    }
}