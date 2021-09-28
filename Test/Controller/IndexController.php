<?php
namespace Test\Controller;

use Swoolefy\Core\App;
use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;

class IndexController extends BController {

    public function index()
    {
        Application::getApp()->response->write('<h1>Hello, Welcome to Swoolefy Framework! <h1>');
    }


    public function testLog()
    {
        /**
         * @var \Swoolefy\Util\Log $log
         */
        $log = Application::getApp()->log;
        $log->addInfo('test-log-id='.rand(1,1000));
        $this->returnJson([
            'Controller' => $this->getControllerId(),
            'Action' => $this->getActionId()
        ]);
    }

    public function testUserList()
    {
        /**
         * @var \Common\Library\Db\Mysql $db
         */
        $db = Application::getApp()->get('db');
        $count = $db->createCommand("select count(1) as total from tbl_users")->count();
        if($count)
        {
            $list = $db->createCommand('select * from tbl_users')->queryAll();
        }
        $db1 = Application::getApp()->get('db');
        $this->returnJson([
            'total' => $count,
            'list' => $list ?? []
        ]);
    }

    public function testAddUser()
    {
        /**
         * @var \Common\Library\Db\Mysql $db
         */
        $db = Application::getApp()->get('db');
        $db->createCommand("insert into tbl_order (`order_id`,`user_id`,`order_amount`,`order_product_ids`,`order_status`) values(:order_id,:user_id,:order_amount,:order_product_ids,:order_status)" )
            ->insert([
                ':order_id' => time() + 5,
                ':user_id' => 10000,
                ':order_amount' => 105,
                ':order_product_ids' => json_encode([1,2,3,rand(1,1000)]),
                ':order_status' => 1
            ]);

        $rowCount = $db->getNumRows();

        $this->returnJson([
            'row_count' => $rowCount
        ]);
    }

    public function testRedis()
    {
        /**
         * @var \Common\Library\Cache\Redis $redis
         */
        $redis = Application::getApp()->get('redis');
        $redis->set('name','bingcool-'.rand(1,1000));
        $value = $redis->get('name');
        $this->returnJson(['value' => $value]);
    }

    public function testPredis()
    {
        /**
         * @var \Common\Library\Cache\Predis $predis
         */
        $predis = Application::getApp()->get('predis');
        $predis->set('predis-name','bingcool-'.rand(1,1000));
        $value = $predis->get('predis-name');
        $this->returnJson(['value' => $value]);
    }

    /**
     * @param int $uid
     * @param int $offset
     * @param int $limit
     */
    public function testOrderList(int $uid, int $page = 1, int $limit = 20)
    {
        /**
         * @var \Common\Library\Db\Mysql $db
         */
        $db = Application::getApp()->get('db');
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
}