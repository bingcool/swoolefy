<?php
namespace Test\Controller;

use Swoolefy\Core\Controller\BController;

class ExceptionController extends BController
{
    public function test() {
        var_dump('test exception');
        throw new \RuntimeException('test throw  exception');
    }
}