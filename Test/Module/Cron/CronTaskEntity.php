<?php
namespace Test\Module\Cron;

use Common\Library\Db\Concern\SoftDelete;
use Test\Model\ClientModel;

// 生成的表【cron_task】的属性
/**
 * @property int id
 * @property string name 任务名称
 * @property string expression cron表达式
 * @property string command 执行命令
 * @property int exec_type 执行类型 1-shell，2-http
 * @property int status 状态 0-禁用，1-启用
 * @property int with_block_lapping 是否阻塞执行 0-否，1->是
 * @property string description 描述
 * @property string cron_between json类型-允许执行时间段
 * @property string cron_skip json类型-不允许执行时间段(即需跳过的时间段)
 * @property string created_at 创建时间
 * @property string updated_at 修改时间
 * @property string deleted_at 删除时间
 */

class CronTaskEntity extends ClientModel
{
    use SoftDelete;
    use CronTaskEventTrait;

    /**
     * @var string
     */
    protected static $table = 'cron_task';

    /**
     * @var string
     */
    protected $pk = 'id';

    protected $casts = [
        'cron_between' => 'array',
        'cron_skip'    => 'array'
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