<?php
namespace Test\Process\TickProcess;

use Swoolefy\Core\Application;
use Swoolefy\Core\Process\ProcessController;
use Swoolefy\Core\Timer\TickManager;

class TickController extends ProcessController {

    public function tickTest($data, $timer_id)
    {
        // 获取Db组件,操作数据
        /**
         * @var \Common\Library\Db\Mysql $db
         */
        $db = Application::getApp()->get('db');
        $total = $db->createCommand('select count(1) as total from tbl_users')->count();
        var_dump('This is TickController, class='.__CLASS__.', User Total='.$total);

        foreach(\Swoole\Timer::list() as $timer_id) {
            // var_dump(\Swoole\Timer::info($timer_id));
        }
    }
}