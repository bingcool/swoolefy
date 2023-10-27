<?php
namespace Test\Module\Order;

use Test\Model\ClientModel;

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
