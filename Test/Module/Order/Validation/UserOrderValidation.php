<?php
namespace Test\Module\Order\Validation;

class UserOrderValidation
{
    public function userList(): array
    {
        return [
            'rules' => [
                //'name' => '',
//                'order_ids' => 'required|array',
//                'order_ids.*' => 'int'
            ],

            'messages' => [
                //'name.int' => '名称必须为整行',
                //'name.between' => '值只能是0,1',
               // 'name.json' => '名称必须为整行',
            ]
        ];
    }
}