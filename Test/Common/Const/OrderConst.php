<?php
namespace Test\Common\Const;
class OrderConst
{
    /**
     * 订单状态
     */
    const OrderStatus = [
        'WAIT_PAY' => 1,
        'WAIT_DELIVER' => 2,
        'WAIT_RECEIVE' => 3,
        'WAIT_COMMENT' => 4,
        'FINISH' => 5,
        'CANCEL' => 6,
        'REFUND' => 7,
        'REFUND_SUCCESS' => 8,
        'REFUND_FAIL' => 9,
    ];
}
