<?php
namespace Test\Module\Demo\Controller;

use Swoolefy\Core\Controller\BController;

class DemoController extends BController
{
    public function test()
    {
        $this->returnJson(['desc' => 'this is a demo']);
    }
}