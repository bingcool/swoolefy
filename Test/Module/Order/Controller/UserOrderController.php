<?php
namespace Test\Module\Order\Controller;

use Common\Library\Db\Query;
use phpseclib3\Math\PrimeField\Integer;
use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Http\RequestInput;
use Test\App;
use Test\Logger\RunLog;
use Test\Module\Order\Dto\UserOrderDto\UserListDto;
use Test\Module\Order\OrderEntity;
use Test\Module\Order\OrderList;
use OpenApi\Attributes as OA;

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
        $sql = $db->newQuery()->table(OrderEntity::getTableName())->when($uid > 90,function (Query $query) {
            $query->where('user_id','>', '100')->limit(0,10);
        })->fetchSql()->select();

        $data = (new OrderEntity())->getAttributes();
        //var_dump($data);

        // Entity 链路方式查询
        $num1 = (new OrderEntity())->getQuery()->where('user_id','=', 10000)->limit(1)->select();
        //var_dump($num1);

        // 列表方式查询
        $orderList = new OrderList();
        $orderList->setUserId([10000]);
        $orderList->setPage(1);
        $orderList->setPageSize(10);
        $count = $orderList->total();
        $list  = $orderList->find();

//        $namemsg = App::getTranslator()->trans('hello');
//        var_dump($namemsg);

        goApp(function () {
            // 列表方式查询
            $orderList = new OrderList();
            $orderList->setUserId([101,102]);
            $count = $orderList->total();
            $list  = $orderList->find();
        });

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
        $orderList = new OrderList();
        $orderList->setUserId([101,102]);
        $count = $orderList->total();
        $list  = $orderList->find();


        $this->returnJson([
            'total' => $count,
            'list'  => $list
        ]);
    }
}