<?php
namespace Test\Module\Demo\Validation;

use Test\Module\Demo\Controller\DemoController;

class DemoValidation
{
    /**
     * @see DemoController::test()
     * 验证规则
     *
     * @return array
     */
    public function test(): array
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