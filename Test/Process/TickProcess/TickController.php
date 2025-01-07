<?php
namespace Test\Process\TickProcess;

use Swoolefy\Core\Coroutine\Context;
use Test\App;
use Swoolefy\Core\Process\ProcessController;

class TickController extends ProcessController {

    public function tickTest($data, $timer_id)
    {
        var_dump($data, $timer_id);
        $total = App::getDb()->createCommand('select count(1) as total from tbl_users')->count();
        var_dump('This is TickController, class='.__CLASS__.', User Total='.$total);
        $contextData = Context::get('test-tick');
    }
}