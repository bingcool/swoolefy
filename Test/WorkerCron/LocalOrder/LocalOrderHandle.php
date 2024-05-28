<?php
namespace Test\WorkerCron\LocalOrder;

use Swoolefy\Core\Application;
use Swoolefy\Core\Crontab\AbstractCronController;
use Swoolefy\Core\Log\LogManager;
use Swoolefy\Core\Swfy;
use Swoolefy\Core\SystemEnv;
use Swoolefy\Worker\AbstractBaseWorker;
use Test\Factory;
use Test\Logger\RunLog;

class LocalOrderHandle extends AbstractCronController {

    public function doCronTask($cron, string $cronName)
    {
//        $db = Factory::getDb();
//        $db->createCommand("insert into tbl_order (`order_id`,`receiver_user_name`,`receiver_user_phone`,`user_id`,`order_amount`,`order_product_ids`,`order_status`) values(:order_id,:receiver_user_name,:receiver_user_phone,:user_id,:order_amount,:order_product_ids,:order_status)" )
//            ->insert([
//                ':order_id' => Application::getApp()->get('uuid')->getOneId(),
//                ':receiver_user_name' => '张三',
//                ':receiver_user_phone' => '12345666',
//                ':user_id' => 10000,
//                ':order_amount' => 105,
//                ':order_product_ids' => json_encode([1,2,3,rand(1,1000)]),
//                ':order_status' => 1
//            ]);
//
//        var_dump(env("HOST_NAME"));
//        var_dump(env('HOST_PASSWORD'));
//        var_dump(Swfy::getConf()['bjg']);
        SystemEnv::clearEnvRepository();
        RunLog::info("this is a cron test log");
        var_dump("cron start");
        sleep(3);
        var_dump(SystemEnv::get('WEB_SITE_HOST'));


        sleep(60);
        AbstractBaseWorker::getProcessInstance()->reboot(3);
        var_dump("cron end");

//        goApp(function() {
//            $db = Factory::getDb();
//            $db->beginTransaction();
//            try {
//                $db->createCommand("insert into tbl_order (`order_id`,`receiver_user_name`,`receiver_user_phone`,`user_id`,`order_amount`,`order_product_ids`,`order_status`) values(:order_id,:receiver_user_name,:receiver_user_phone,:user_id,:order_amount,:order_product_ids,:order_status)" )
//                    ->insert([
//                        ':order_id' => Application::getApp()->get('uuid')->getOneId(),
//                        ':receiver_user_name' => '张三',
//                        ':receiver_user_phone' => '12345666',
//                        ':user_id' => 10000,
//                        ':order_amount' => 105,
//                        ':order_product_ids' => json_encode([1,2,3,rand(1,1000)]),
//                        ':order_status' => 1
//                    ]);
//
//                $db->commit();
//                var_dump('commit');
//            }catch (\Throwable $e) {
//                $db->rollback();
//                var_dump($e->getMessage());
//            }
//        });
    }
}