<?php
namespace Test\Module\Demo\Controller;

use Swoolefy\Core\Controller\BController;

class DemoController extends BController
{
    public function test()
    {
        $this->returnJson(['desc' => 'this is a demo']);
    }

    public function test1()
    {
        $this->returnJson(['desc' => 'this is a demo2-'.rand(1,10000)]);
    }
}