<?php
namespace Test\Process\SubscribeProcess;

use Swoolefy\Core\Application;
use Swoolefy\Core\EventController;
use Test\Module\Order\OrderList;

class SubscribeHandle extends EventController
{
    public function doRun($msg)
    {
        var_dump('handle msg = '. $msg);
//        $orderList = new OrderList();
//        $orderList->setUserId([101,102]);
//        $count = $orderList->total();
//        var_dump($count);
    }
}