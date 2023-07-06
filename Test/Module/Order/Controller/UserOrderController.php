<?php
namespace Test\Module\Order\Controller;

use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;
use Test\Module\Order\OrderList;

class UserOrderController extends BController
{
    public function userList()
    {
        /**
         * @var \Common\Library\Db\Mysql $db
         */
//        $db = Application::getApp()->get('db');
//        $query = $db->newQuery()->table('tbl_users')->where('user_id','>', '100')->limit(0,10);
//        $count = $query->count();
//
//        if ($count > 0) {
//            $list = $query->select()->toArray();
//        }

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