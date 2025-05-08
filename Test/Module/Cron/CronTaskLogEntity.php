<?php
namespace Test\Module\Cron;

use Common\Library\Db\Concern\SoftDelete;
use Test\Model\ClientModel;

// 生成的表【cron_task_log】的属性
/**
 * @property int id
 * @property int cron_id 关联的cron_task_id
 * @property string exec_batch_id 每轮执行的批次id
 * @property string task_item json类型-执行任务项meta信息
 * @property string message 运行态记录信息
 * @property string created_at 创建时间
 * @property string updated_at 修改时间
 * @property string deleted_at 删除时间
 */

class CronTaskLogEntity extends ClientModel
{
    use SoftDelete;
    use CronTaskEventTrait;

    /**
     * @var string
     */
    protected static $table = 'cron_task_log';

    /**
     * @var string
     */
    protected $pk = 'id';

    protected $casts = [
        'task_item' => 'array',
    ];

    /**
     * @param $id
     */
    public function loadById($id)
    {
        return $this->loadOne([
            'id' => $id,
        ]);
    }
}