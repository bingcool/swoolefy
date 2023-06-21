<?php
namespace Test\Model;

use Swoolefy\Core\Application;
use Common\Library\Db\Model;
use Common\Library\Db\PDOConnection;
use Swoolefy\Core\Swfy;

class ClientModel extends Model {

    /**
     * @var int
     */
    protected $userId;

    /**
     * ClientModel constructor.
     * @param int $userId
     * @param int $id
     */
    public function __construct(int $userId, int $id = 0)
    {
        $this->userId = $userId;
        parent::__construct($userId);
    }

    /**
     * @inheritDoc
     */
    public function getConnection()
    {
//        return Application::getApp()->creatObject('client_db', function ($comName) {
//            // 通过$this->userId动态获取对应数据库配置
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

    /**
     * @param $userId
     * @return mixed
     */
    public static function getDbClient($userId)
    {
        $key = 'client_db_user_id_'.$userId;
        return Application::getApp()->creatObject($key, function ($comName) {
            // 通过$this->userId动态获取对应数据库配置
            return call_user_func(Swfy::getAppConf()['components']['db']);
        });
    }
}