<?php
namespace Test\Controller;

use Swoolefy\Core\Controller\BController;

class ValidateController extends BController
{
    public function test1()
    {
        $params = [
//            'name'=>'bingcool',
//            'id'  =>22222,
            'mail' => 'ggg',
            'user_ids' => [
                888,7777,'vv'
            ],

            'user_ids1' => [
                ['id' => 0],
            ],

            'user_ids2' => [
                'ids' => [],
                'page_id' => 1
            ]
        ];

        $this->validate($params, [
            // 'id' => 'required',
//            'mail' =>'require|email',
//            'user_ids' => 'required|array',
//            'user_ids.*' => 'int',

//            'user_ids1' => 'required|array',
//            'user_ids1.*.id' => 'require'


            'user_ids2' => 'required|array',
            'user_ids2.ids.id' => 'require'
        ],

        [
           'id' => 'id必填'
        ]
        );

        $this->returnJson([]);
    }

}