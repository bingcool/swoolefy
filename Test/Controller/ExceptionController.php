<?php
namespace Test\Controller;

use Swoolefy\Core\Controller\BController;

class ExceptionController extends BController
{
    public function test(): array
    {
        var_dump('test exception');
        trigger_error('trigger error');
        return [
            'name'=>'bingcool-'.rand(1,1000)
        ];
    }
}