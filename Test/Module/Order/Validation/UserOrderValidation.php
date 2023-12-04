<?php
namespace Test\Module\Order\Validation;

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
}