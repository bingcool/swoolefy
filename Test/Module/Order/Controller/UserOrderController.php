<?php
namespace Test\Module\Order\Controller;

use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;

class UserOrderController extends BController
{
    public function userList()
    {
        /**
         * @var \Common\Library\Db\Mysql $db
         */
        $db = Application::getApp()->get('db');
        $count = $db->createCommand("select count(1) as total from tbl_users")->count();
        if($count) {
            $list = $db->createCommand('select * from tbl_users order by user_id desc limit 0, 10')->queryAll();
        }

        $this->returnJson($list);
    }
}