<?php
namespace Test\Module\Order\Validation;

use OpenApi\Attributes as OA;
use Test\Module\Swag;


class LogOrderValidation
{
    #[OA\Get(
        path: '/user/user-order/logOrder',
        summary:'订单日志',
        description:'获取订单日志',
        tags: [Swag::MODULE_TAG_LOG],// 根据Swag.php的注册的tag值来设置，相同的tag的接口将汇集在同一个模块下
        security: [['apiKeyAuth' => []], ['appId' => []]], //指定了在哪些接口上应用SecurityScheme中已经定义的安全方案
    )]

    #[OA\Response(
        response: 200,
        description: '操作成功',
    )]
    public function testLog()
    {
        return [
            'rules' => [
            ],

            'messages' => [

            ]
        ];
    }
}