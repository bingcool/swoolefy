<?php
namespace Test\Controller;

use GuzzleHttp\Client;
use http\Header;
use OpenTelemetry\SDK\Common\Http\Psr\Client\Discovery\Guzzle;
use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Core\Coroutine\Context;
use Swoolefy\Core\EventController;
use Swoolefy\Core\Log\Formatter\LineFormatter;
use Swoolefy\Core\Log\LogManager;
use Swoolefy\Core\Swoole;
use Swoolefy\Http\RequestInput;
use Test\App;
use Test\Logger\RunLog;

class IndexController extends BController {

    /**
     * @var \Common\Library\Db\Mysql
     */
    protected $db;

    public function index()
    {
        // todo something

        // 创建一个协程单例异步写入日志
        goApp(function () {
            // 写入文件,遇到write(), swoole底层将使用底层封装的io_uring实现异步
            var_dump("开始写入日志文件");
            // 此时，发生系统IO调用，会触发协程调度，cpu切换到其他协程执行逻辑
            file_put_contents("/tmp/mylog.txt", "日志测试异步写入");
            // 异步写入完成后，内核通知唤醒协程，继续执行逻辑
            var_dump("完成写入日志文件");
            $contextData = \Swoolefy\Core\Coroutine\Context::getContext()->getArrayCopy();
            var_dump($contextData);
        });

        // 写入日志到文件，发生协程挂起，cpu继续运行主流程，执行逻辑
        var_dump("这是一个测试swoole的demo");

        // todo something

        return $this->returnJson([
            'code' => 200,
            'msg' => 'success',
            'data' => []
        ]);

        //var_dump("root-go-cid=".\Swoole\Coroutine::getCid());
//        goApp(function () {
//
//            $client = new Client([
//                'handler' => \Common\Library\CurlProxy\CurlProxyHandler::getStackHandler(),
//            ]);
//            $client->request('GET', 'http://127.0.0.1:9501/user/user-order/userList?name=bingcool',[
//                'headers' => [
//                    'User-Agent' => 'MyApp/1.0',         // 自定义 User-Agent
//                    'Authorization' => 'Bearer YOUR_TOKEN', // 认证头
//                    'X-Custom-Header' => 'value',        // 自定义头
//                    'Accept' => 'application/json',      // 指定响应格式
//                ],
//            ]);
//
//            sleep(3);
//
//            $client = new Client([
//                'handler' => \Common\Library\CurlProxy\CurlProxyHandler::getStackHandler(),
//            ]);
//            $client->request('GET', 'http://127.0.0.1:9501/user/user-order/userList?name=bingcool',[
//                'headers' => [
//                    'User-Agent' => 'MyApp/1.0',         // 自定义 User-Agent
//                    'Authorization' => 'Bearer YOUR_TOKEN', // 认证头
//                    'X-Custom-Header' => 'value',        // 自定义头
//                    'Accept' => 'application/json',      // 指定响应格式
//                ],
//            ]);
//
//            goApp(function () {
//                $client = new Client([
//                    'handler' => \Common\Library\CurlProxy\CurlProxyHandler::getStackHandler(),
//                ]);
//                $client->request('GET', 'http://127.0.0.1:9501/user/user-order/userList?name=bingcool',[
//                    'headers' => [
//                        'User-Agent' => 'MyApp/1.0',         // 自定义 User-Agent
//                        'Authorization' => 'Bearer YOUR_TOKEN', // 认证头
//                        'X-Custom-Header' => 'value',        // 自定义头
//                        'Accept' => 'application/json',      // 指定响应格式
//                    ],
//                ]);
//            });
//        });

//        (new Client([
//            'handler' => \Common\Library\CurlProxy\CurlProxyHandler::getStackHandler(),
//            'base_uri' => 'https://www.baidu.com',
//        ]))->get('/', []);

        $client = (new Client([
            'handler' => \Common\Library\CurlProxy\CurlProxyHandler::getStackHandler(),
        ]))->request('GET', 'http://127.0.0.1:9501/user/user-order/userList?name=bingcool');

        goApp(function () {
            //sleep(1);
            $client = (new Client([
                'handler' => \Common\Library\CurlProxy\CurlProxyHandler::getStackHandler(),
            ]))->request('GET', 'http://127.0.0.1:9501/user/user-order/userList?name=bingcool');

            sleep(2);

            $client = (new Client([
                'handler' => \Common\Library\CurlProxy\CurlProxyHandler::getStackHandler(),
            ]))->request('GET', 'http://127.0.0.1:9501/user/user-order/userList1?name=bingcool');

            $client = (new Client([
                'handler' => \Common\Library\CurlProxy\CurlProxyHandler::getStackHandler(),
            ]))->request('GET', 'http://127.0.0.1:9501/user/user-order/userList1?name=bingcool');

            $client = (new Client([
                'handler' => \Common\Library\CurlProxy\CurlProxyHandler::getStackHandler(),
            ]))->request('GET', 'http://127.0.0.1:9501/user/user-order/userList1?name=bingcool');

            $client = (new Client([
                'handler' => \Common\Library\CurlProxy\CurlProxyHandler::getStackHandler(),
            ]))->request('GET', 'http://127.0.0.1:9501/user/user-order/userList1?name=bingcool');

            $client = (new Client([
                'handler' => \Common\Library\CurlProxy\CurlProxyHandler::getStackHandler(),
                'base_uri' => 'https://www.baidu.com/',
            ]))->get('/', []);

        });

//        sleep(3);
//
//        $client = new Client([
//            'handler' => \Common\Library\CurlProxy\CurlProxyHandler::getStackHandler(),
//        ]);
//        $client->request('GET', 'http://127.0.0.1:9501/user/user-order/userList?name=bingcool',[
//            'headers' => [
//                'User-Agent' => 'MyApp/1.0',         // 自定义 User-Agent
//                'Authorization' => 'Bearer YOUR_TOKEN', // 认证头
//                'X-Custom-Header' => 'value',        // 自定义头
//                'Accept' => 'application/json',      // 指定响应格式
//            ],
//        ]);
//
//        $client = new Client([
//            'handler' => \Common\Library\CurlProxy\CurlProxyHandler::getStackHandler(),
//            'base_uri' => 'https://www.baidu.com/',
//        ])->get('/', []);

        Application::getApp()->swooleResponse->status(200);
        Application::getApp()->swooleResponse->write('<h1>Hello, Welcome to Swoolefy Framework! <h1>');
    }


    public function testLog(RequestInput $requestInput)
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
            'Controller' => $requestInput->getControllerId(),
            'Action' => $requestInput->getActionId().'-'.rand(1,1000)
        ]);
    }
    public function afterSave()
    {

    }

    public function testLog1(RequestInput $requestInput)
    {
        RunLog::info('test11111-log-id='.rand(1,1000));
        $this->returnJson([
            'Controller' => $requestInput->getControllerId(),
            'Action' => $requestInput->getActionId()
        ]);
    }


    public function testAddUser()
    {
        $db = App::getDb();
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
            $db = App::getDb();
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
        $db = App::getDb();
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
        $db = App::getDb();
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
        RunLog::info("Hello");

        $this->db = App::getDb();

        $this->db->newQuery()->query(
            "insert into tbl_order (`order_id`,`receiver_user_name`,`receiver_user_phone`,`user_id`,`order_amount`,`address`,`order_product_ids`,`order_status`) values(:order_id,:receiver_user_name,:receiver_user_phone,:user_id,:order_amount,:address,:order_product_ids,:order_status)",
            [
                ':order_id' => App::getUUid()->getOneId(),
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
                        ':order_id' => App::getUUid()->getOneId(),
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
                    ':order_id' => App::getUUid()->getOneId(),
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
            $db1 = App::getDb();
            var_dump('beginTransaction');
            try {
                $db1->beginTransaction();
                $db1->newQuery()->query(
                    "insert into tbl_order (`order_id`,`receiver_user_name`,`receiver_user_phone`,`user_id`,`order_amount`,`address`,`order_product_ids`,`order_status`) values(:order_id,:receiver_user_name,:receiver_user_phone,:user_id,:order_amount,:address,:order_product_ids,:order_status)",
                    [
                        ':order_id' => App::getUUid()->getOneId(),
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