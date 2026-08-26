<?php
namespace Test\Module\Cron\Entity;

use Test\Model\ClientModel;

/**
 * 主键是 id。group_id 只存在于 cron_agent_node（节点所属分组），本表没有该列。
 *
 * @property int id
 * @property string group_name 分组名称
 * @property string remark 备注
 * @property string created_at 创建时间
 * @property string updated_at 修改时间
 */
class CronAgentNodeGroupEntity extends ClientModel
{
    /**
     * @var string
     */
    protected static $table = 'cron_agent_node_group';

    /**
     * @var string
     */
    protected $pk = 'id';

    /**
     * @param int $id
     * @return CronAgentNodeGroupEntity
     */
    public function loadById(int $id): \Swoolefy\Library\Db\Model
    {
        return $this->loadOne([
            'id' => $id,
        ]);
    }
}
