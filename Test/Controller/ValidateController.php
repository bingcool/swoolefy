<?php
namespace Test\Controller;

use Swoolefy\Annotation\ApiOperation;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Http\RequestInput;

class ValidateController extends BController
{
    /**
     * @Api 测试请求参数校验 Validate
     *
     * curl -X GET 'http://127.0.0.1:9501/api/validate-test1'
     */
    #[ApiOperation(description: '测试请求参数校验 Validate')]
    public function test1(RequestInput $requestInput): array
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

        $requestInput->validate($params, [
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

        return [];
    }

}
