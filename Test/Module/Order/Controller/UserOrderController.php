<?php
namespace Test\Module\Order\Controller;

use Swoolefy\Core\Controller\BController;

class UserOrderController extends BController
{
    public function userList()
    {
        $this->returnJson([
            [
                'id' => 11111,
                'name' => 'aaaaaa'
            ]
        ]);
    }
}