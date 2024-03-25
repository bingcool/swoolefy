<?php
namespace Test\Module\Order\Validation;

use OpenApi\Attributes as OA;
use Test\Module\Swag;

class UserOrderValidation
{
    public function userList(): array
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