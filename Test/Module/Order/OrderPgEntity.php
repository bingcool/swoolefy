<?php
namespace Test\Module\Order;

use Swoolefy\Core\Application;
use Swoolefy\Core\Swfy;
use Test\Model\ClientModel;

class OrderPgEntity extends ClientModel
{
    use OrderEventTrait;

    /**
     * @var string
     */
    protected static $table = 'tbl_order';

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
     * @inheritDoc
     */
    public function getConnection()
    {
        // 通过query获取user对应所在的dbId
        $dbId = 2;
        $dbIdKey = 'db-id-'.$dbId;
        return Application::getApp()->creatObject($dbIdKey, function ($comName) {
            // 通过$this->userId动态获取对应数据库配置
            return call_user_func(Swfy::getAppConf()['components']['pg']);
        });
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
