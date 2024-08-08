<?php

namespace Test\WorkerDaemon;

use Common\Library\Db\Raw;
use Swoolefy\Core\Application;
use Swoolefy\Core\EventController;
use Swoolefy\Core\Log\LogManager;
use Test\Logger\RunLog;
use Test\Module\Order\OrderEntity;

class PipeWorkerProcess extends \Swoolefy\Worker\AbstractWorkerProcess
{

    public function loopHandle()
    {
        $a = 1;
        $b = 2;
        $c = 3;
        goApp(function ($a, $b) use($c) {
                goApp(function () use($a, $b) {
                    goApp(function () use($a, $b) {
                        var_dump($a, $b);
                    });
                });
            }, $a, $b);

            var_dump('start start');

        RunLog::info("loopHandle");

        sleep(120);
        return;

        $userId = 10000;
        $receiver_user_name = '李四';
        $receiver_user_phone = '12344556';
        $order_amount =100;
        $address = "广东省深圳xxxxxx";
        $order_product_ids = [1222,345,567,rand(1,1000)];
        $json_data = ['name'=>'xiaomi', 'phone'=>123456789];
        $order_status = 1;
        $remark = 'test-remark-'.rand(1,1000);

        /**
         * @var \Common\Library\Db\Pgsql $pg
         */
        $pg = Application::getApp()->get('pg');
        /**
         * @var \Common\Library\Db\Query $query
         */

        $query = $pg->newQuery();
        $orderId = 1717658580;
        $data = [
            'user_id' => 10000,
            'receiver_user_name' => $receiver_user_name,
            'receiver_user_phone' => $receiver_user_phone,
            'order_amount' => $order_amount,
            'address' => $address,
            'order_product_ids' => json_encode($order_product_ids),
            'json_data' => json_encode($json_data),
            'order_status' => $order_status,
            'remark' => $remark
        ];


//        $result = $query->table('tbl_order')->updateOrCreateOne([
//                'order_id' => $orderId
//            ],
//            $data
//        );

        /**
         * @var \Common\Library\Db\Mysql $db
         */
        $db = Application::getApp()->get('db');
        $query = $db->newQuery();
        $result = $query->table('tbl_order')->order('order_id', 'desc')->limit(1)->select();
        foreach ($result as $item)
        {
            /**
             * @var OrderEntity $order
             */
            $order = (new OrderEntity($userId, 0))->fill($item);
            var_dump($order->order_product_ids);
        }

            sleep(60);
            var_dump("end end end ");
    }

//    public function run()
//    {
//        //Application::getApp()->get('log')->addInfo('pllllllllllll');
////        while (1) {
////            if ($this->isExiting()) {
////                sleep(1);
////                continue;
////            }
////
//////            LogManager::getInstance()->getLogger('log')->info('kkkkkkkkkkkkkkkk');
//////            var_dump('CID='.\Swoole\Coroutine::getCid());
//////            var_dump('PipeWorker');
////            $a = 1;
////            $b = 2;
////            $c = 3;
////            goApp(function ($a, $b) use($c) {
////                goApp(function () use($a, $b) {
////                    goApp(function () use($a, $b) {
////                        var_dump($a, $b);
////                    });
////                });
////            }, $a, $b);
////
////            var_dump('start start');
////            sleep(120);
////            var_dump("gggggggggggggggggggggggggg");
////        }
//
//
//
////        $db = Application::getApp()->get('db');
////        $result = $db->createCommand('select * from tbl_users limit 1')->queryAll();
////        dump($result);
//
////        \Swoole\Coroutine::create(function () {
////            (new \Swoolefy\Core\EventApp)->registerApp(function (EventController $eventApp)  {
////                var_dump('mmmmmmmmmmmmmmmmmmmmmmmmm');
////            });
////        });
//
//
//
////        if($this->isWorker0()) {
////            $this->notifyMasterCreateDynamicProcess($this->getProcessName(), 1);
////        }
//
//        var_dump($this->limitCurrentRunCoroutineNum);
////        if($this->isWorker0()) {
////            sleep(5);
////            $this->reboot();
////        }
//    }

    public function onHandleException(\Throwable $throwable, array $context = [])
    {
        parent::onHandleException($throwable, $context);
        sleep(1);
        var_dump($throwable->getMessage());
    }
}