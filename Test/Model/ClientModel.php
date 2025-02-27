<?php
namespace Test\Model;

use Swoolefy\Core\Application;
use Common\Library\Db\Model;
use Swoolefy\Core\Swfy;

class ClientModel extends Model {

    /**
     * @var int
     */
    protected $userId;

    /**
     * @inheritDoc
     */
    public function getConnection()
    {
        if (is_object($this->connection)) {
            return $this->connection;
        }
        // 通过query获取user对应所在的dbId
//        $dbId = 2;
//        $dbIdKey = 'db-id-'.$dbId;
//        return Application::getApp()->creatObject($dbIdKey, function ($comName) {
//                // 通过$this->userId动态获取对应数据库配置
//                return call_user_func(Swfy::getAppConf()['components']['db']);
//        });
        return Application::getApp()->get('db');
    }

    /**
     * @return int|mixed
     */
    public function createPkValue()
    {
        sleep(1);
        return time();
    }
}