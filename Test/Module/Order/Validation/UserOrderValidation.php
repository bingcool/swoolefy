<?php
namespace Test\Module\Order\Validation;

use OpenApi\Attributes as OA;
use Test\Module\Swag;

class UserOrderValidation
{
    // Post请求的惨胡
    #[OA\Post(
        path: '/user/user-order/userList',
        summary:'订单列表',
        description:'获取订单列表内容',
        tags: [Swag::MODULE_TAG_ORDER], // 根据Swag.php的注册的tag值来设置，相同的tag的接口将汇集在同一个模块下
        security: [['apiKeyAuth' => []], ['appId' => []]], //指定了在哪些接口上应用SecurityScheme中已经定义的安全方案
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: "application/json",// 或者application/x-www-form-urlencoded
                schema: new OA\Schema(
                    type: 'object',
                    required:['name'],
                    properties: [
                        // 字符串
                        new OA\Property(property: 'name', type: 'string', description:'名称'
                        ),
                        // 字符串
                        new OA\Property(property: 'email', type: 'string', description:'邮件'
                        ),
                        // 整型
                        new OA\Property(property: 'product_num', type: 'integer', description:'产品数量'
                        ),
                        // 数组 phone => [1111, 22222]
                        new OA\Property(property: 'phone', type: 'array', description:'电话', items: new OA\Items(
                            type: 'integer'
                        )),

                        // 一维关联数组(对象) address = ['sheng' => '广东省', 'city' => '深圳市'，'area'=>'宝安区'],
                        new OA\Property(property: 'address', type: 'object', description:'居住地址',
                            properties:[
                                // sheng
                                new OA\Property(property: 'sheng', type: 'string', description:'省份'
                                ),
                                // city
                                new OA\Property(property: 'city', type: 'string', description:'城市'
                                ),
                                // area
                                new OA\Property(property: 'area', type: 'string', description:'县/区'
                                ),
                            ]
                        ),

                        // 二维关联数组 addressList => [
                        //      ['sheng' => '广东省', 'city' => '深圳市'，'area'=>'宝安区'],
                        //      ['sheng' => '广东省', 'city' => '深圳市'，'area'=>'宝安区']
                        //  ]
                        new OA\Property(property: 'addressList', type: 'array', description:'地址列表', items: new OA\Items(
                            type: 'object',
                            properties:[
                                // sheng
                                new OA\Property(property: 'sheng', type: 'string', description:'省份'
                                ),
                                // city
                                new OA\Property(property: 'city', type: 'string', description:'城市'
                                ),
                                // area
                                new OA\Property(property: 'area', type: 'string', description:'县/区'
                                ),
                            ]
                        )),
                    ]
                )
            )
        )
    )]

    #[OA\Response(
        response: 200,
        description: '操作成功'
    )]


    /**
     * @see \Test\Module\Order\Controller\UserOrderController::userList()
     */
    public function userList(): array
    {
        return [
            'rules' => [
                //'name' => 'required|int',
//                'order_ids' => 'required|array',
//                'order_ids.*' => 'int'
            ],

            'messages' => [
//                'name.required' => '名称必须',
//                'name.json' => '名称必须json字符串',
            ]
        ];
    }

    /**
     * @return array[]
     */
    #[OA\Get(
        path: '/user/user-order/userList1',
        summary:'订单列表111',
        description:'获取订单列表内容111',
        tags: [Swag::MODULE_TAG_ORDER],// 根据Swag.php的注册的tag值来设置，相同的tag的接口将汇集在同一个模块下
        security: [['apiKeyAuth' => []], ['appId' => []]], //指定了在哪些接口上应用SecurityScheme中已经定义的安全方案
    )]
    // Get Query
    #[OA\QueryParameter(name: 'order_id', description: "订单ID", required: true, allowEmptyValue: false, allowReserved: true, schema: new OA\Schema(type:'integer')
    )]
    // Get Query
    #[OA\QueryParameter(name: 'product_name', description: '产品名称', required: true, allowEmptyValue: true, allowReserved: true, schema: new OA\Schema(type:'string')
    )]

    // Get Query array eg: ids[1]=22&ids[2]=333
    #[OA\QueryParameter(name: 'product_ids', description: '产品ids', required: true, allowEmptyValue: false, allowReserved: true, schema: new OA\Schema(
        type:'array',
        items: new OA\Items(
            type:'integer'
        )
    ))]
    #[OA\Response(
        response: 200,
        description: '操作成功'
    )]
    public function userList1(): array
    {
        return [
            'rules' => [
                //'name' => 'required|float|json',
//                'order_ids' => 'required|array',
//                'order_ids.*' => 'int'
            ],

            'messages' => [
//                'name.required' => '名称必须',
//                'name.json' => '名称必须json字符串',
            ]
        ];
    }
}