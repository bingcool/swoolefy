<?php
namespace Test\Module\Order;

use Common\Library\Db\Concern\SoftDelete;
use Test\Model\ClientModel;

// 生成的表【tbl_order】的属性
/**
 * @property int order_id 订单id
 * @property int user_id 下单用户id
 * @property string receiver_user_name 收货人
 * @property string receiver_user_phone 收货人手机号
 * @property float order_amount 订单金额
 * @property string order_product_ids 订单产品id
 * @property int order_status 订单状态
 * @property string address 物流地址
 * @property string remark 评论
 * @property string expend_data json类型-数据
 * @property string json_data 扩展数据
 * @property string gmt_create 创建时间
 * @property string gmt_modify 更新时间
 * @property string deleted_at 删除时间
 */

class OrderEntity extends ClientModel
{
    use SoftDelete;
    use OrderEventTrait;

    /**
     * @var string
     */
    protected static $table = 'tbl_order';

    /**
     * @var string
     */
    protected $pk = 'order_id';

    protected $casts = [
        'json_data' => 'array'
    ];

    /**
     * 定义场景来处理不同的事件数据
     * @var string
     */
    protected $scene;

    /**
     * @param $id
     */
    public function loadById($id)
    {
        return $this->loadOne([
            'order_id' => $id,
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

    public function getOrderProductIdsAttr($value)
    {
        return json_decode($value, true);
    }

//    /**
//     * @param $value
//     * @return false|string
//     */
//    public function setJsonDataAttr($value)
//    {
//        if (is_array($value)) {
//            return json_encode($value, JSON_UNESCAPED_UNICODE);
//        }
//        return $value;
//    }
//
//    /**
//     * @param $value
//     * @return array
//     */
//    public function getJsonDataAttr($value)
//    {
//        if (!is_array($value)) {
//            return json_decode($value, true);
//        }
//        return $value;
//    }

}
