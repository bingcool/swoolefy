<?php
namespace Test\Module\Order\Validation;

class UserOrderValidation
{
    public function userList(): array
    {
        return [
            'rules' => [
                'name' => 'int',
                'order_ids' => 'array',
                'order_ids.*' => 'int'
            ],

            'messages' => [
                'name' => '名称必传',
            ]
        ];
    }
}