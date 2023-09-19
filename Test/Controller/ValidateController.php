<?php
namespace Test\Controller;

use Swoolefy\Core\Controller\BController;

class ValidateController extends BController
{
    public function test1()
    {
        $params = [
            'name'=>'bingcool',
            'id'  =>22222,
            'mail' => 'ggg@qq.com'
        ];

        $this->validate($params, [
            'id' => 'required',
            'mail' =>'require|email'
        ],
        [
           'id' => 'id必填'
        ]
        );

        $this->returnJson([]);
    }

}