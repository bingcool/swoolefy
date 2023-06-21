<?php
namespace Test\Module\Order;

use Test\Model\ClientModel;

class OrderEntity extends ClientModel
{
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
        return $this->findOne('order_id=:order_id and user_id=:user_id', [
            ':order_id' => $id,
            ':user_id' => $this->userId
        ]);
    }

    /**
     * @return bool
     */
    public function onBeforeInsert(): bool
    {

        // todo
        var_dump(__CLASS__.'::'.__FUNCTION__);
        return true;
    }

    public function onAfterInsert()
    {
        // todo
        var_dump(__CLASS__.'::'.__FUNCTION__);
    }

    public function onBeforeUpdate(): bool
    {
        // todo
        var_dump(__CLASS__.'::'.__FUNCTION__);
        return true;
    }

    public function onAfterUpdate()
    {
        // todo
        var_dump(__CLASS__.'::'.__FUNCTION__);
    }

    /**
     * @param $value
     * @return false|string
     */
    public function setOrderProductIdsAttr($value)
    {
        if(is_array($value)) {
            return json_encode($value);
        }

        return $value;
    }

    /**
     * @param $value
     * @return false|string
     */
    public function setJsonDataAttr($value)
    {
        if(is_array($value)) {
            return json_encode($value);
        }

        return $value;
    }

}
