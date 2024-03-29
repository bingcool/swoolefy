<?php
namespace Test\Module\Order;

use Test\Model\ClientModel;

/**
 * @property int order_id 订单id
 * @property int user_id 下单用户id
 * @property string receiver_user_name 收货人
 * @property string receiver_user_phone 收货人手机号
 * @property string order_amount 订单金额
 * @property string order_product_ids 订单产品id
 * @property int order_status 订单状态
 * @property string address 物流地址
 * @property string remark 评论
 * @property string json_data 扩展数据
 * @property string gmt_create 创建时间
 * @property string gmt_modify 更新时间
 */

class OrderEntity extends ClientModel
{
    use OrderEventTrait;

    /**
     * @var string
     */
    protected $table = 'tbl_order';

    /**
     * @var string
     */
    protected $pk = 'order_id';

    /**
     * 定义场景来处理不同的事件数据
     * @var string
     */
    protected $scene;

    /**
     * OrderEntity constructor.
     * @param $userId
     * @param int $id
     */
    public function __construct($userId, $id = 0)
    {
        parent::__construct($userId, $id);

        if($id > 0) {
            $this->loadById($id);
        }
    }

    /**
     * @param $id
     */
    public function loadById($id)
    {
        return $this->loadOne([
            'order_id' => $id,
            'user_id' => $this->userId
        ]);
    }

    /**
     * @param $value
     * @return false|string
     */
    public function setOrderProductIdsAttr($value)
    {
        if(is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return $value;
    }

    /**
     * @param $value
     * @return false|string
     */
    public function setJsonDataAttr($value)
    {
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return $value;
    }

}
