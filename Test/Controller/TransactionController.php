<?php
namespace Test\Controller;

use Swoolefy\Core\Controller\BController;
use Test\App;

class TransactionController extends BController
{
    public function test()
    {
        $db = App::getDb();
        $db->beginTransaction();
        try {
            goApp(function () {
                // 协程单例不受外部的事务影响，db连接实例都是不一样的
                $db = App::getDb();
                $db->createCommand("insert into tbl_users (`user_id`,`user_name`,`sex`,`birthday`,`phone`) values(:user_name,:sex,:birthday,:phone)" )
                    ->insert([
                        ':user_id' => 46457,
                        ':user_name' => '李四-'.rand(1,9999),
                        ':sex' => 0,
                        ':birthday' => '1991-07-08',
                        ':phone' => 12345678
                    ]);

                $rowCount = $db->getNumRows();
                var_dump("c1=".$rowCount);
            });

            $db->beginTransaction();
            try {
                $db->createCommand("insert into tbl_users (`user_name`,`sex`,`birthday`,`phone`) values(:user_name,:sex,:birthday,:phone)" )
                    ->insert([
                        ':user_name' => '陈飞-'.rand(1,9999),
                        ':sex' => 0,
                        ':birthday' => '1991-07-08',
                        ':phone' => 12345678
                    ]);
                $rowCount = $db->getNumRows();
                $db->commit();
                var_dump("c3=".$rowCount);
            } catch (\Exception $e) {
                var_dump($e->getMessage());
                $db->rollBack();
                throw $e;
            }

            $db->createCommand("insert into tbl_users (`user_name`,`sex`,`birthday`,`phone`) values(:user_name,:sex,:birthday,:phone)" )
                ->insert([
                    ':user_name' => '张三-'.rand(1,9999),
                    ':sex' => 0,
                    ':birthday' => '1991-07-08',
                    ':phone' => 12345678
                ]);

            $rowCount = $db->getNumRows();
            var_dump("c2=".$rowCount);
            $db->commit();
        } catch (\Exception $e) {
            var_dump($e->getMessage());
            $db->rollBack();
            throw $e;
        }

    }
}