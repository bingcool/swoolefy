<?php
namespace Test\Module\Order\Controller;

use Common\Library\Db\Query;
use Common\Library\Db\Raw;
use Common\Library\Db\Sql;
use GuzzleHttp\Client;
use malkusch\lock\mutex\PgAdvisoryLockMutex;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Http\RequestInput;
use Test\App;
use Test\Logger\RunLog;
use Test\Module\Order\Dto\UserOrderDto\UserListDto;
use Test\Module\Order\OrderEntity;
use Test\Module\Order\OrderList;
use Test\Mytest;

class UserOrderController extends BController
{

    public function _beforeAction(RequestInput $requestInput, string $action)
    {
        var_dump('_beforeAction='.$action);
    }
    /**
     * @return void
     * @see \Test\Module\Order\Validation\UserOrderValidation::userList()
     */
    public function userList(RequestInput $requestInput, UserListDto $userListDto)
    {
        $db = App::getDb();

//
//        $t3 = Sql::table("tbl_banks")->as('t3');
//        $sql = $db->newQuery()->table($t3)
//            ->field([1])
//            ->where(function ($query) use($t3) {
//                $query->whereOr($t3->C('name'), "bank-198");
//                $query->whereOr([
//                    'id' => 100011
//                ]);
//
//        })->where([
//                $t3->C('user_id') => 2000
//        ])
//        ->fetchSql()
//        ->select();
//
//        var_dump($sql);
//
//        return $this->returnJson([
//            'total' => $count ?? 0,
//            'list'  => []
//        ]);

//        $query = $db->newQuery()->table('tbl_users')->where('user_id','>', '100')->limit(0,10);
//        $count = $query->count();
//        if ($count > 0) {
//            $list = $query->select()->toArray();
//        }
//
//        var_dump($requestInput->get());
//
//        var_dump($userListDto->name);

        $uid = 100;
        $pageList = $db->newQuery()->table(OrderEntity::getTableName())->when($uid > 90,function (Query $query) {
            $query->where('user_id','>', '0');
        })->paginateX(1,1706266299345000000);

        var_dump($pageList->items(), $pageList->lastId());

        $data = (new OrderEntity())->getAttributes();
        //var_dump($data);

        // Entity 链路方式查询
        $query = (new OrderEntity())->getQuery()
            ->where('user_id','>', 0);

        $total1 = $query->clone()->count();
        $list1  = $query->limit(1)->select();

        //var_dump($total1, $list1);

        $subTable = (new OrderEntity())->getQuery()
            ->table(OrderEntity::getTableName())
            ->where([
                'user_id' => 10000
            ])->limit(1)
            ->buildSql();

        $t1 = Sql::table($subTable)->as('t1');

        $t2 = Sql::table("tbl_users")->as('t2');

        $t3 = Sql::table("tbl_banks")->as('t3');

        /**.
         * @var \Common\Library\Db\Query $query
         */
        $querySql = App::getDb()->newQuery()
            ->field([
                $t1->C('user_id'),
            ])
            ->from($t1)->whereExists(function ( Query $query) use($t1, $t2) {
            $query->table($t2)
                ->field($t2->C('*'))
                ->whereColumn($t1->C('user_id'),'=', $t2->C('user_id'));
        })->when(true, function ($query) use($t1) {
            $query->where($t1->C('user_id'), '<>', 1);
        })
        ->whereExists(function ($query) use($t1, $t3) {
            /**
             * @var \Common\Library\Db\Query $query
             */
            $query->table($t3)
                ->field([1])
                ->whereColumn($t1->C('user_id'),'=', $t3->C('id'))
                ->where(function ($query) use($t3) {
                    $query->whereOr($t3->C('name'), "bank-198");
                    $query->whereOr([
                        $t3->C('id') => 100011
                    ]);
                });
        })->join($t3, Sql::andOn(
                    Sql::on($t1->C('user_id'), $t3->C('id')),
                    Sql::on($t1->C('user_id'), $t3->C('id')),
                ),
        )->whereRaw(sprintf("%s=%d", $t1->C('user_id'), 10000000000))
            ->order([$t1->C('user_id') => 'desc'])
        ->fetchSql()
            ->select();

        var_dump($querySql);


        // 列表方式查询
       // $orderList = new OrderList();
//        $orderList->setUserId([10000]);
//        $orderList->setPage(1);
//        $orderList->setPageSize(10);
//        $count = $orderList->total();
//        $list  = $orderList->find();

        goApp(function () {
            // 列表方式查询
            $orderList = new OrderList();
            $orderList->setUserId([10000]);
            $orderList->setPage(1);
            $orderList->setPageSize(10);
            $count = $orderList->total();
            $list  = $orderList->find();
        });

        goApp(function () {
            // 列表方式查询
            $orderList = new OrderList();
            $orderList->setUserId([10000]);
            $orderList->setPage(1);
            $orderList->setPageSize(10);
            $count = $orderList->total();
            $list  = $orderList->find();
        });

//        $namemsg = App::getTranslator()->trans('hello');
//        var_dump($namemsg);

        goApp(function () {
            // 列表方式查询
            $orderList = new OrderList();
            $orderList->setUserId([101,102]);
            $count = $orderList->total();
            $list  = $orderList->find();
        });

//        goApp(function () {
//            $client = new Client([
//                'handler' => \Common\Library\CurlProxy\CurlProxyHandler::getStackHandler(),
//            ]);
//            $client->request('GET', 'http://127.0.0.1:9501/user/user-order/userList1?name=bingcool',[
//                'headers' => [
//                    'User-Agent' => 'MyApp/1.0',         // 自定义 User-Agent
//                    'Authorization' => 'Bearer YOUR_TOKEN', // 认证头
//                    'X-Custom-Header' => 'value',        // 自定义头
//                    'Accept' => 'application/json',      // 指定响应格式
//                ],
//            ]);
//        });

        $client = new Client([
                'handler' => \Common\Library\CurlProxy\CurlProxyHandler::getStackHandler(),
            ]);
        $client->request('GET', 'http://127.0.0.1:9501/user/user-order/userList1?name=bingcool',[
            'headers' => [
                'User-Agent' => 'MyApp/1.0',         // 自定义 User-Agent
                'Authorization' => 'Bearer YOUR_TOKEN', // 认证头
                'X-Custom-Header' => 'value',        // 自定义头
                'Accept' => 'application/json',      // 指定响应格式
            ],
        ]);

        RunLog::info("userList userList userList");

//        goApp(function () {
//            (new Client([
//                'handler' => \Common\Library\CurlProxy\CurlProxyHandler::getStackHandler(),
//                'base_uri' => 'https://www.baidu.com',
//            ]))->get('/', []);
//        });

       (new Client([
            'handler' => \Common\Library\CurlProxy\CurlProxyHandler::getStackHandler(),
            'base_uri' => 'https://www.baidu.com',
        ]))->get('/', []);

        $this->returnJson([
            'total' => $count ?? 0,
            'list'  => []
        ]);
    }

    public function userList1()
    {
//        $db = App::getDb();
//        $query = $db->newQuery()->table('tbl_users')->where('user_id','>', '100')->limit(0,10);
//        $count = $query->count();
//        if ($count > 0) {
//            $list = $query->select()->toArray();
//        }
//
//        var_dump($this->request->get);

        // 列表方式查询
        goApp(function () {
            // 列表方式查询
            $orderList = new OrderList();
            $orderList->setUserId([10000]);
            $orderList->setPage(1);
            $orderList->setPageSize(10);
            $count = $orderList->total();
            $list  = $orderList->find();
        });

        goApp(function () {
            // 列表方式查询
            $orderList = new OrderList();
            $orderList->setUserId([10000]);
            $orderList->setPage(1);
            $orderList->setPageSize(10);
            $count = $orderList->total();
            $list  = $orderList->find();
        });


        $this->returnJson([
            'total' => $count ?? 0,
            'list'  => $list ?? []
        ]);
    }
}