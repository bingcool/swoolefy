<?php
namespace Test\Controller;

use Swoolefy\Core\Controller\BController;

class ExceptionController extends BController
{
    public function test() {
        var_dump('test exception');
        trigger_error('trigger error');
        throw new \RuntimeException('RuntimeException RuntimeException');
        $this->returnJson(['name'=>'bingcool-'.rand(1,1000)]);
    }
}