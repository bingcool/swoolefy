<?php

namespace Test\Module\Order\Validation;

/**
 * 订单相关校验规则（供 Controller / 旧式 validate 引用）。
 * OpenAPI 文档请用 gen:apidoc + Request DTO 注解。
 */
class UserOrderValidation
{
    /**
     * @see \Test\Module\Order\Controller\UserOrderController::userList()
     */
    public function userList(): array
    {
        return [
            'rules' => [
                'order_ids' => 'required|array',
                'order_ids.*' => 'int',
            ],
            'messages' => [
            ],
        ];
    }

    /**
     * @return array{rules: array<string, string>, messages: array<string, string>}
     */
    public function userList1(): array
    {
        return [
            'rules' => [
            ],
            'messages' => [
            ],
        ];
    }
}
