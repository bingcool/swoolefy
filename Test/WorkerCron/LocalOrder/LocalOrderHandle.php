<?php
namespace Test\WorkerCron\LocalOrder;

use Swoolefy\Core\Application;
use Swoolefy\Core\Crontab\AbstractCronController;

class LocalOrderHandle extends AbstractCronController {

    public function doCronTask($cron, string $cronName)
    {
        var_dump(date('Y-m-d H:i:s'));

        $db = Application::getApp()->get('db');
        $db->createCommand("insert into tbl_order (`order_id`,`receiver_user_name`,`receiver_user_phone`,`user_id`,`order_amount`,`order_product_ids`,`order_status`) values(:order_id,:receiver_user_name,:receiver_user_phone,:user_id,:order_amount,:order_product_ids,:order_status)" )
            ->insert([
                ':order_id' => time() + 5,
                ':receiver_user_name' => '张三',
                ':receiver_user_phone' => '12345666',
                ':user_id' => 10000,
                ':order_amount' => 105,
                ':order_product_ids' => json_encode([1,2,3,rand(1,1000)]),
                ':order_status' => 1
            ]);

        var_dump('save');
        goApp(function() {
            /**
             * @var \Common\Library\Db\Mysql $db
             */
            $db = Application::getApp()->get('db');
            $db->beginTransaction();
            try {
                $db->createCommand("insert into tbl_order (`order_id`,`receiver_user_name`,`receiver_user_phone`,`user_id`,`order_amount`,`order_product_ids`,`order_status`) values(:order_id,:receiver_user_name,:receiver_user_phone,:user_id,:order_amount,:order_product_ids,:order_status)" )
                    ->insert([
                        ':order_id' => time() + 6,
                        ':receiver_user_name' => '张三',
                        ':receiver_user_phone' => '12345666',
                        ':user_id' => 10000,
                        ':order_amount' => 105,
                        ':order_product_ids' => json_encode([1,2,3,rand(1,1000)]),
                        ':order_status' => 1
                    ]);

                $db->commit();
                var_dump('commit');
            }catch (\Throwable $e) {
                $db->rollback();
                var_dump($e->getMessage());
            }
        });
    }
}