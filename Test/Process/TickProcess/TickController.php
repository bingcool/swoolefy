<?php
namespace Test\Process\TickProcess;

use Test\Factory;
use Swoolefy\Core\Process\ProcessController;

class TickController extends ProcessController {

    public function tickTest($data, $timer_id)
    {
        var_dump($data, $timer_id);
        $total = Factory::getDb()->createCommand('select count(1) as total from tbl_users')->count();
        var_dump('This is TickController, class='.__CLASS__.', User Total='.$total);

        $list = \Swoole\Timer::list();
        foreach($list as $timer_id) {
            var_dump(\Swoole\Timer::info($timer_id));
        }
    }
}