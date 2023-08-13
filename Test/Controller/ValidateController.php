<?php
namespace Test\Controller;

use Swoolefy\Core\Controller\BController;

class ValidateController extends BController
{
    public function test1(string $name, array $ids)
    {
        $params = [
            'name'=>'bingcool',
            'id'  =>22222,
            'mail' => 'ggg@qq.com'
        ];

        $this->validate($params, [
            'id' => 'require',
            'mail' =>'require|email'
        ],
        [
            'id' => 'id必填'
        ]
        );

        $this->returnJson([]);
    }

}