<?php
namespace Test\Controller;

use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Core\EventController;
use Swoolefy\Core\Log\Formatter\LineFormatter;
use Swoolefy\Core\Log\LogManager;
use Test\Factory;
use Test\Logger\Log;

class IndexController extends BController {

    /**
     * @var \Common\Library\Db\Mysql
     */
    protected $db;

    public function index()
    {
        $log = LogManager::getInstance()->getLogger('log');
        $log->addInfo('test11111-log-id='.rand(1,1000),true, ['name'=>'bingcoolhuang']);
        Application::getApp()->response->write('<h1>Hello, Welcome to Swoolefy Framework! <h1>');
    }


    public function testLog()
    {
        /**
         * @var \Swoolefy\Util\Log $log
         */
        $log = Application::getApp()->get('log');
        $formatter = new LineFormatter("%message%\n");
        $log->setFormatter($formatter);
        $log->setLogFilePath($log->getLogFilePath());
        $log->addInfo(['name' => 'bingcool','address'=>'深圳'],true, ['name'=>'bincool','sex'=>1,'address'=>'shenzhen']);
        Application::getApp()->afterRequest([$this, 'afterSave']);


        LogManager::getInstance()->getLogger('sql_log')->addInfo(['name' => 'bingcoolcccccccccccccccccccccccccc','address'=>'深圳']);

        $this->returnJson([
            'Controller' => $this->getControllerId(),
            'Action' => $this->getActionId().'-'.rand(1,1000)
        ]);
    }
    public function afterSave()
    {

    }

    public function testLog1()
    {
        $log = LogManager::getInstance()->getLogger('log');
        $log->addInfo('test11111-log-id='.rand(1,1000),true);
        $this->returnJson([
            'Controller' => $this->getControllerId(),
            'Action' => $this->getActionId()
        ]);
    }


    public function testAddUser()
    {
        $db = Factory::getDb();
        $db->createCommand("insert into tbl_users (`user_name`,`sex`,`birthday`,`phone`) values(:user_name,:sex,:birthday,:phone)" )
            ->insert([
                ':user_name' => '李四-'.rand(1,9999),
                ':sex' => 0,
                ':birthday' => '1991-07-08',
                ':phone' => 12345678
            ]);

        $rowCount = $db->getNumRows();

        // 创建一个协助程单例
        goApp(function () use($rowCount) {
            $db = Factory::getDb();
            $db->createCommand("insert into tbl_users (`user_name`,`sex`,`birthday`,`phone`) values(:user_name,:sex,:birthday,:phone)" )
                ->insert([
                    ':user_name' => '李四-'.rand(1,9999),
                    ':sex' => 0,
                    ':birthday' => '1991-07-08',
                    ':phone' => 12345678
                ]);

            $rowCount = $db->getNumRows();

        });

        $this->returnJson([
            'row_count' => $rowCount
        ]);


    }

    public function testUserList()
    {
        $db = Factory::getDb();
        $count = $db->createCommand("select count(1) as total from tbl_users")->count();
        if($count) {
            $list = $db->createCommand('select * from tbl_users')->queryAll();
        }
        $this->returnJson([
            'total' => $count,
            'list' => $list ?? []
        ]);
    }

    /**
     * @param int $uid
     * @param int $offset
     * @param int $limit
     */
    public function testOrderList(int $uid, int $page = 1, int $limit = 20)
    {
        $db = Factory::getDb();
        $offset = ($page -1) * $limit;

        $count = $db->createCommand("select count(1) as total from tbl_order where user_id=:uid")->count([':uid'=>$uid]);

        if($count)
        {
            $list = $db->createCommand("select * from tbl_order where user_id=:uid limit :offset, :limit")->queryAll([
                ':uid' => $uid,
                ':offset' => $offset,
                ':limit' => $limit
            ]);
        }

        $this->returnJson([
            'total' => $count,
            'list' => $list ?? []
        ]);
    }

    public function testTransactionAddOrder()
    {
        Log::info("Hello");

        $this->db = Factory::getDb();

        $this->db->newQuery()->query(
            "insert into tbl_order (`order_id`,`receiver_user_name`,`receiver_user_phone`,`user_id`,`order_amount`,`address`,`order_product_ids`,`order_status`) values(:order_id,:receiver_user_name,:receiver_user_phone,:user_id,:order_amount,:address,:order_product_ids,:order_status)",
            [
                ':order_id' => Factory::getUUid()->getOneId(),
                ':receiver_user_name' => '张三-444555',
                ':receiver_user_phone' => '12345666',
                ':user_id' => 10000,
                ':order_amount' => 105,
                ':address' => "深圳市宝安区xxxx",
                ':order_product_ids' => json_encode([1,2,3,rand(1,1000)]),
                ':order_status' => 1
            ]);

        goApp(function ()  {
            try {
                $this->db->beginTransaction();
                $this->db->newQuery()->query(
                    "insert into tbl_order (`order_id`,`receiver_user_name`,`receiver_user_phone`,`user_id`,`order_amount`,`address`,`order_product_ids`,`order_status`) values(:order_id,:receiver_user_name,:receiver_user_phone,:user_id,:order_amount,:address,:order_product_ids,:order_status)",
                    [
                        ':order_id' => Factory::getUUid()->getOneId(),
                        ':receiver_user_name' => '张三-992',
                        ':receiver_user_phone' => '12345666',
                        ':user_id' => 10000,
                        ':order_amount' => 105,
                        ':address' => "深圳市宝安区xxxx",
                        ':order_product_ids' => json_encode([1,2,3,rand(1,1000)]),
                        ':order_status' => 1
                    ]);

                $this->db->commit();
                var_dump('db commit');
            }catch (\Throwable $exception) {
                $this->db->rollback();
                var_dump('exception='.$exception->getMessage());
            }


            $this->db->newQuery()->query(
                "insert into tbl_order (`order_id`,`receiver_user_name`,`receiver_user_phone`,`user_id`,`order_amount`,`address`,`order_product_ids`,`order_status`) values(:order_id,:receiver_user_name,:receiver_user_phone,:user_id,:order_amount,:address,:order_product_ids,:order_status)",
                [
                    ':order_id' => Factory::getUUid()->getOneId(),
                    ':receiver_user_name' => '张三-992',
                    ':receiver_user_phone' => '12345666',
                    ':user_id' => 10000,
                    ':order_amount' => 105,
                    ':address' => "深圳市宝安区xxxx",
                    ':order_product_ids' => json_encode([1,2,3,rand(1,1000)]),
                    ':order_status' => 1
                ]);


        });

        goApp(function()  {
            $db1 = Factory::getDb();
            var_dump('beginTransaction');
            try {
                $db1->beginTransaction();
                $db1->newQuery()->query(
                    "insert into tbl_order (`order_id`,`receiver_user_name`,`receiver_user_phone`,`user_id`,`order_amount`,`address`,`order_product_ids`,`order_status`) values(:order_id,:receiver_user_name,:receiver_user_phone,:user_id,:order_amount,:address,:order_product_ids,:order_status)",
                    [
                        ':order_id' => Factory::getUUid()->getOneId(),
                        ':receiver_user_name' => '张三-2',
                        ':receiver_user_phone' => '12345666',
                        ':user_id' => 10000,
                        ':order_amount' => 105,
                        ':address' => "深圳市宝安区xxxx",
                        ':order_product_ids' => json_encode([1,2,3,rand(1,1000)]),
                        ':order_status' => 1
                    ]);


                $db1->commit();

                var_dump('commit');

            }catch (\Throwable $e) {
                var_dump($e->getMessage());
                $db1->rollback();
            }
        });

        $this->returnJson([
            'num' => rand(1,1000)
        ]);

    }
}