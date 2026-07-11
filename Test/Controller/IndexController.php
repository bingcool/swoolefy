<?php
namespace Test\Controller;

use Swoolefy\Library\OpenTelemetry\SDK\Common\Configuration\Parser\BooleanParser;
use GuzzleHttp\Client;
use http\Header;
use OpenTelemetry\SDK\Common\Http\Psr\Client\Discovery\Guzzle;
use Swoolefy\Annotation\ApiOperation;
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
use function Swoolefy\Library\OpenTelemetry\API\Trace\trace;
use function Psl\Type\bool;

class IndexController extends BController {

    /**
     * @var \Swoolefy\Library\Db\Mysql
     */
    protected $db;

    /**
     * @Api("测试首页入口：协程写日志、环境变量与欢迎页")
     *
     * curl 测试：
     * curl "http://127.0.0.1:9501/index/index"
     * curl "http://127.0.0.1:9501/api/"
     */
    #[ApiOperation(description: '测试首页入口：协程写日志、环境变量与欢迎页')]
    public function index(RequestInput $request): string
    {
        // todo something

        var_dump(env('MY_APP_NAME'));

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

        RunLog::info("test index index");

        // 写入日志到文件，发生协程挂起，cpu继续运行主流程，执行逻辑
        var_dump("这是一个测试swoole的demo");

        // todo something

        return env('MY_APP_NAME');

        //var_dump("root-go-cid=".\Swoole\Coroutine::getCid());
//        goApp(function () {
//
//            $client = new Client([
//                'handler' => \Swoolefy\Library\CurlProxy\CurlProxyHandler::getStackHandler(),
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
//                'handler' => \Swoolefy\Library\CurlProxy\CurlProxyHandler::getStackHandler(),
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
//                    'handler' => \Swoolefy\Library\CurlProxy\CurlProxyHandler::getStackHandler(),
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
//            'handler' => \Swoolefy\Library\CurlProxy\CurlProxyHandler::getStackHandler(),
//            'base_uri' => 'https://www.baidu.com',
//        ]))->get('/', []);

        $client = (new Client([
            'handler' => \Swoolefy\Library\CurlProxy\CurlProxyHandler::getStackHandler(),
        ]))->request('GET', 'http://127.0.0.1:9501/user/user-order/userList?name=bingcool');

        goApp(function () {
            //sleep(1);
            $client = (new Client([
                'handler' => \Swoolefy\Library\CurlProxy\CurlProxyHandler::getStackHandler(),
            ]))->request('GET', 'http://127.0.0.1:9501/user/user-order/userList?name=bingcool');

            sleep(2);

            $client = (new Client([
                'handler' => \Swoolefy\Library\CurlProxy\CurlProxyHandler::getStackHandler(),
            ]))->request('GET', 'http://127.0.0.1:9501/user/user-order/userList1?name=bingcool');

            $client = (new Client([
                'handler' => \Swoolefy\Library\CurlProxy\CurlProxyHandler::getStackHandler(),
            ]))->request('GET', 'http://127.0.0.1:9501/user/user-order/userList1?name=bingcool');

            $client = (new Client([
                'handler' => \Swoolefy\Library\CurlProxy\CurlProxyHandler::getStackHandler(),
            ]))->request('GET', 'http://127.0.0.1:9501/user/user-order/userList1?name=bingcool');

            $client = (new Client([
                'handler' => \Swoolefy\Library\CurlProxy\CurlProxyHandler::getStackHandler(),
            ]))->request('GET', 'http://127.0.0.1:9501/user/user-order/userList1?name=bingcool');

            $client = (new Client([
                'handler' => \Swoolefy\Library\CurlProxy\CurlProxyHandler::getStackHandler(),
                'base_uri' => 'https://www.baidu.com/',
            ]))->get('/', []);

        });

//        sleep(3);
//
//        $client = new Client([
//            'handler' => \Swoolefy\Library\CurlProxy\CurlProxyHandler::getStackHandler(),
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
//            'handler' => \Swoolefy\Library\CurlProxy\CurlProxyHandler::getStackHandler(),
//            'base_uri' => 'https://www.baidu.com/',
//        ])->get('/', []);

        Application::getApp()->swooleResponse->status(\Swoole\Http\Status::OK);
        Application::getApp()->swooleResponse->write('<h1>Hello, Welcome to Swoolefy Framework! <h1>');
        return true;
    }


    /**
     * @Api("测试日志组件写入与 afterRequest 回调注册")
     *
     * curl 测试：
     * curl "http://127.0.0.1:9501/api/index/testLog"
     */
    #[ApiOperation(description: '测试日志组件写入与 afterRequest 回调注册')]
    public function testLog(RequestInput $requestInput): array
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

        return [
            'Controller' => $requestInput->getControllerId(),
            'Action' => $requestInput->getActionId().'-'.rand(1,1000)
        ];
    }
    /**
     * @Api("请求结束后回调，供 afterRequest 内部调用")
     * 无 HTTP 路由（回调/内部用）
     */
    public function afterSave()
    {

    }

    /**
     * @Api("测试 RunLog 业务日志写入")
     * 无 HTTP 路由
     */
    public function testLog1(RequestInput $requestInput): array
    {
        RunLog::info('test11111-log-id='.rand(1,1000));
        return [
            'Controller' => $requestInput->getControllerId(),
            'Action' => $requestInput->getActionId()
        ];
    }


    /**
     * @Api("测试插入用户表（含协程内再次插入）")
     *
     * curl 测试：
     * curl "http://127.0.0.1:9501/user/testAddUser"
     */
    #[ApiOperation(description: '测试插入用户表（含协程内再次插入）')]
    public function testAddUser(): array
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

        return [
            'row_count' => $rowCount
        ];


    }

    /**
     * @Api("测试查询用户列表与总数")
     * 无 HTTP 路由
     */
    public function testUserList(): array
    {
        $db = App::getDb();
        $count = $db->createCommand("select count(1) as total from tbl_users")->count();
        if($count) {
            $list = $db->createCommand('select * from tbl_users')->queryAll();
        }
        return [
            'total' => $count,
            'list' => $list ?? []
        ];
    }

    /**
     * @Api("按用户分页查询订单列表（内部方法带参数）")
     * 无 HTTP 路由（内部方法带参数）
     *
     * @param int $uid
     * @param int $page
     * @param int $limit
     */
    public function testOrderList(int $uid, int $page = 1, int $limit = 20): array
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

        return [
            'total' => $count,
            'list' => $list ?? []
        ];
    }

    /**
     * @Api("测试事务与协程场景下插入订单")
     *
     * curl 测试：
     * curl "http://127.0.0.1:9501/user/testTransactionAddOrder"
     */
    #[ApiOperation(description: '测试事务与协程场景下插入订单')]
    public function testTransactionAddOrder(): array
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

        return [
            'num' => rand(1,1000)
        ];

    }
}