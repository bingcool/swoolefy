<?php

namespace Test\Controller;

use GuzzleHttp\Client;
use Swoolefy\Annotation\ApiOperation;
use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Core\Log\Formatter\LineFormatter;
use Swoolefy\Core\Log\LogManager;
use Swoolefy\Http\RequestInput;
use Test\App;
use Test\Logger\RunLog;

/**
 * Test 应用首页与基础 DB/日志演示。
 *
 * 路由见 Test/Router/Common/Index.php（每个 action 同一 HTTP 方法仅一条路径）。
 */
class IndexController extends BController
{
    /**
     * @var \Swoolefy\Library\Db\Mysql|null
     */
    protected $db;

    /**
     * 测试首页：协程写日志、环境变量、CurlProxy 示例请求。
     *
     * Route: GET /index/index
     *
     * ```bash
     * curl -X GET 'http://127.0.0.1:9501/index/index'
     * ```
     */
    #[ApiOperation(description: '测试首页入口：协程写日志、环境变量与欢迎页')]
    public function index(RequestInput $request): string
    {
        var_dump(env('MY_APP_NAME'));

        goApp(function () {
            var_dump('开始写入日志文件');
            file_put_contents('/tmp/mylog.txt', '日志测试异步写入');
            var_dump('完成写入日志文件');
            var_dump(\Swoolefy\Core\Coroutine\Context::getContext()->getArrayCopy());
        });

        RunLog::info('test index index');
        var_dump('这是一个测试swoole的demo');

        // CurlProxy：主协程一次请求 + 子协程并发示例
        (new Client([
            'handler' => \Swoolefy\Library\CurlProxy\CurlProxyHandler::getStackHandler(),
        ]))->request('GET', 'http://127.0.0.1:9501/user/user-order/userList?name=bingcool');

        goApp(function () {
            (new Client([
                'handler' => \Swoolefy\Library\CurlProxy\CurlProxyHandler::getStackHandler(),
            ]))->request('GET', 'http://127.0.0.1:9501/user/user-order/userList?name=bingcool');

            sleep(2);

            (new Client([
                'handler' => \Swoolefy\Library\CurlProxy\CurlProxyHandler::getStackHandler(),
            ]))->request('GET', 'http://127.0.0.1:9501/user/user-order/userList1?name=bingcool');
        });

        Application::getApp()->swooleResponse->status(\Swoole\Http\Status::OK);
        Application::getApp()->swooleResponse->write('<h1>Hello, Welcome to Swoolefy Framework! <h1>');

        return (string) env('MY_APP_NAME');
    }

    /**
     * 测试 Application 日志组件、sql_log 与 afterRequest 回调。
     *
     * Route: GET /api/index/testLog
     *
     * ```bash
     * curl -X GET 'http://127.0.0.1:9501/api/index/testLog' \
     *   -H 'Accept: application/json'
     * ```
     */
    #[ApiOperation(description: '测试日志组件写入与 afterRequest 回调注册')]
    public function testLog(RequestInput $requestInput): array
    {
        /** @var \Swoolefy\Util\Log $log */
        $log = Application::getApp()->get('log');
        $formatter = new LineFormatter("%message%\n");
        $log->setFormatter($formatter);
        $log->setLogFilePath($log->getLogFilePath());
        $log->addInfo(
            ['name' => 'bingcool', 'address' => '深圳'],
            true,
            ['name' => 'bincool', 'sex' => 1, 'address' => 'shenzhen']
        );
        Application::getApp()->afterRequest([$this, 'afterSave']);

        LogManager::getInstance()->getLogger('sql_log')->addInfo([
            'name' => 'bingcoolcccccccccccccccccccccccccc',
            'address' => '深圳',
        ]);

        return [
            'Controller' => $requestInput->getControllerId(),
            'Action' => $requestInput->getActionId() . '-' . rand(1, 1000),
        ];
    }

    /**
     * 请求结束后回调（afterRequest），非 HTTP 路由。
     */
    public function afterSave(): void
    {
    }

    /**
     * 测试 RunLog 业务日志写入（与 testLog 覆盖不同日志通道）。
     *
     * Route: GET /api/index/testLog1
     *
     * ```bash
     * curl -X GET 'http://127.0.0.1:9501/api/index/testLog1' \
     *   -H 'Accept: application/json'
     * ```
     */
    #[ApiOperation(description: '测试 RunLog 业务日志写入')]
    public function testLog1(RequestInput $requestInput): array
    {
        RunLog::info('test11111-log-id=' . rand(1, 1000));

        return [
            'Controller' => $requestInput->getControllerId(),
            'Action' => $requestInput->getActionId(),
        ];
    }

    /**
     * 测试插入用户表（主流程 + 协程内各插一条）。
     *
     * Route: GET /user/testAddUser
     *
     * ```bash
     * curl -X GET 'http://127.0.0.1:9501/user/testAddUser' \
     *   -H 'Accept: application/json'
     * ```
     */
    #[ApiOperation(description: '测试插入用户表（含协程内再次插入）')]
    public function testAddUser(): array
    {
        $sql = 'insert into tbl_users (`user_name`,`sex`,`birthday`,`phone`) values(:user_name,:sex,:birthday,:phone)';
        $params = static function (): array {
            return [
                ':user_name' => '李四-' . rand(1, 9999),
                ':sex' => 0,
                ':birthday' => '1991-07-08',
                ':phone' => 12345678,
            ];
        };

        $db = App::getDb();
        $db->createCommand($sql)->insert($params());
        $rowCount = $db->getNumRows();

        goApp(function () use ($sql, $params) {
            App::getDb()->createCommand($sql)->insert($params());
        });

        return [
            'row_count' => $rowCount,
        ];
    }

    /**
     * 测试查询用户列表与总数。
     *
     * Route: GET /api/index/testUserList
     *
     * ```bash
     * curl -X GET 'http://127.0.0.1:9501/api/index/testUserList' \
     *   -H 'Accept: application/json'
     * ```
     */
    #[ApiOperation(description: '测试查询用户列表与总数')]
    public function testUserList(): array
    {
        $db = App::getDb();
        $count = $db->createCommand('select count(1) as total from tbl_users')->count();
        $list = $count
            ? $db->createCommand('select * from tbl_users')->queryAll()
            : [];

        return [
            'total' => $count,
            'list' => $list,
        ];
    }

    /**
     * 测试事务与协程场景下插入订单。
     *
     * Route: GET /user/testTransactionAddOrder
     *
     * ```bash
     * curl -X GET 'http://127.0.0.1:9501/user/testTransactionAddOrder' \
     *   -H 'Accept: application/json'
     * ```
     */
    #[ApiOperation(description: '测试事务与协程场景下插入订单')]
    public function testTransactionAddOrder(): array
    {
        RunLog::info('Hello');

        $this->db = App::getDb();
        $insertSql = 'insert into tbl_order (`order_id`,`receiver_user_name`,`receiver_user_phone`,`user_id`,`order_amount`,`address`,`order_product_ids`,`order_status`) values(:order_id,:receiver_user_name,:receiver_user_phone,:user_id,:order_amount,:address,:order_product_ids,:order_status)';

        $orderParams = static function (string $name): array {
            return [
                ':order_id' => App::getUUid()->getOneId(),
                ':receiver_user_name' => $name,
                ':receiver_user_phone' => '12345666',
                ':user_id' => 10000,
                ':order_amount' => 105,
                ':address' => '深圳市宝安区xxxx',
                ':order_product_ids' => json_encode([1, 2, 3, rand(1, 1000)]),
                ':order_status' => 1,
            ];
        };

        $this->db->newQuery()->query($insertSql, $orderParams('张三-444555'));

        goApp(function () use ($insertSql, $orderParams) {
            try {
                $this->db->beginTransaction();
                $this->db->newQuery()->query($insertSql, $orderParams('张三-992'));
                $this->db->commit();
                var_dump('db commit');
            } catch (\Throwable $exception) {
                $this->db->rollback();
                var_dump('exception=' . $exception->getMessage());
            }

            $this->db->newQuery()->query($insertSql, $orderParams('张三-992'));
        });

        goApp(function () use ($insertSql, $orderParams) {
            $db1 = App::getDb();
            var_dump('beginTransaction');
            try {
                $db1->beginTransaction();
                $db1->newQuery()->query($insertSql, $orderParams('张三-2'));
                $db1->commit();
                var_dump('commit');
            } catch (\Throwable $e) {
                var_dump($e->getMessage());
                $db1->rollback();
            }
        });

        return [
            'num' => rand(1, 1000),
        ];
    }
}
