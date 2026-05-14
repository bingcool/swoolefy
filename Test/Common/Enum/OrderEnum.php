<?php
namespace Test\Common\Enum;

Enum OrderEnum: string
{
    case STATUS_WAIT_PAY = 'wait_pay';

    case STATUS_WAIT_DELIVERY = 'wait_delivery';

    case STATUS_WAIT_RECEIVE = 'wait_receive';

    case STATUS_WAIT_COMMENT = 'wait_comment';

    case STATUS_FINISH = 'finish';

    /**
     * @return string
     */
    public function getLabel(): string
    {
        return match($this) {
            self::STATUS_WAIT_PAY => '待付款',
            self::STATUS_WAIT_DELIVERY => '待发货',
            self::STATUS_WAIT_RECEIVE => '待收货',
            self::STATUS_WAIT_COMMENT => '待评价',
            self::STATUS_FINISH => '已完成',
        };
    }

    /**
     * 枚举下拉选择
     *
     * @return OrderEnum[]
     */
    public static function statusOptions(): array
    {
        foreach (self::cases() as $case) {
            $options[] = [
                'value' => $case->value,
                'label' => $case->getLabel(),
            ];
        }
        return $options ?? [];
    }
}
