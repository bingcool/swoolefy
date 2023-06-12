<?php
namespace Test\Controller;

use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Core\EventController;
use Swoolefy\Core\Log\Formatter\LineFormatter;
use Swoolefy\Core\Log\LogManager;

class IndexController extends BController {

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
        /**
         * @var \Common\Library\Db\Mysql $db
         */
        $db = Application::getApp()->get('db');
        $db->createCommand("insert into tbl_users (`user_name`,`sex`,`birthday`,`phone`) values(:user_name,:sex,:birthday,:phone)" )
            ->insert([
                ':user_name' => '李四-'.rand(1,9999),
                ':sex' => 0,
                ':birthday' => '1991-07-08',
                ':phone' => 12345678
            ]);

        $rowCount = $db->getNumRows();

        // 创建一个协助程单例
        goApp(function (EventController $event) use($rowCount) {
            /**
             * @var \Common\Library\Db\Mysql $db
             */
            $db = Application::getApp()->get('db');
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
        /**
         * @var \Common\Library\Db\Mysql $db
         */
        $db = Application::getApp()->get('db');
        $count = $db->createCommand("select count(1) as total from tbl_users")->count();
        if($count) {
            $list = $db->createCommand('select * from tbl_users')->queryAll();
        }
        $db1 = Application::getApp()->get('db');
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

    public function testTransactionAddOrder()
    {
        /**
         * @var \Common\Library\Db\Mysql $db
         */
        $db = Application::getApp()->get('db');

        $db->beginTransaction();

        try {
            $db = Application::getApp()->get('db');
            $db->createCommand("insert into tbl_order (`order_id`,`receiver_user_name`,`receiver_user_phone`,`user_id`,`order_amount`,`address`,`order_product_ids`,`order_status`) values(:order_id,:receiver_user_name,:receiver_user_phone,:user_id,:order_amount,:address,:order_product_ids,:order_status)" )
                ->insert([
                    ':order_id' => time() + 5,
                    ':receiver_user_name' => '张三',
                    ':receiver_user_phone' => '12345666',
                    ':user_id' => 10000,
                    ':order_amount' => 105,
                    ':address' => "深圳市宝安区xxxx",
                    ':order_product_ids' => json_encode([1,2,3,rand(1,1000)]),
                    ':order_status' => 1
                ]);

            $rowCount = $db->getNumRows();

            $db->commit();


            goApp(function(EventController $event) {
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

                }catch (\Throwable $e) {
                    $db->rollback();
                    var_dump($e->getMessage());
                }
            });
        }catch (\Throwable $e) {
            $db->rollback();
            var_dump($e->getMessage());
            return;
        }

        $this->returnJson([
            'num' => $rowCount
        ]);

    }

    public function ws()
    {
        Application::getApp()->response->write('<!DOCTYPE HTML>
<html>
   <head>
   <meta charset="utf-8">
   <title>菜鸟教程(runoob.com)</title>
    
      <script type="text/javascript">
         function WebSocketTest()
         {
            if ("WebSocket" in window)
            {  
               // 打开一个 web socket
               var ws = new WebSocket("ws://127.0.0.1:9502/");
                
               ws.onopen = function()
               {                  
                  ws.send("Chat::mychat::"+ Math.random() * (100 - 1) + 1);
                  alert("数据发送中...");
               };
                
               ws.onmessage = function (evt) 
               { 
                  var received_msg = evt.data;
                  alert("数据已接收，返回的数据:" + received_msg);
               };
                
               ws.onclose = function()
               { 
                  // 关闭 websocket
                  alert("连接已关闭..."); 
               };
            }
            
            else
            {
               // 浏览器不支持 WebSocket
               alert("您的浏览器不支持 WebSocket!");
            }
         }
      </script>
        
   </head>
   <body>
   
      <div id="sse">
         <a href="javascript:WebSocketTest()">运行 WebSocket</a>
      </div>
      
   </body>
</html>');

    }

}