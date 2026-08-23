<?php
namespace Test\Module\Cron;

use Swoolefy\Library\Db\Concern\SoftDelete;
use Swoolefy\Library\Db\Query;
use Test\Model\ClientModel;

/**
 * @property int id
 * @property string node_name 节点名称
 * @property string node_ip 节点IP
 * @property string remark 备注
 * @property string|null last_heartbeat_at 最近一次 Agent 心跳
 * @property int heartbeat_interval 该节点心跳间隔（秒）
 * @property string created_at 创建时间
 * @property string updated_at 修改时间
 * @property string|null deleted_at 删除时间
 */
class CronAgentNodeEntity extends ClientModel
{
    use SoftDelete;
    /**
     * @var string
     */
    protected static $table = 'cron_agent_node';

    /**
     * @var string
     */
    protected $pk = 'id';

    /**
     * 管理端列表 / Dashboard 统计用：排除已软删行。
     *
     * Query::select() 不会自动加 SoftDelete 条件（只有 first()/loadOne 会）。
     */
    public static function queryNotDeleted(): Query
    {
        return static::query()->whereDeletedAtNull(static::getSoftDeleteField());
    }

    /**
     * @param int $id
     * @return static|null
     */
    public function loadById(int $id): ?static
    {
        return $this->loadOne([
            'id' => $id,
        ]);
    }
}
