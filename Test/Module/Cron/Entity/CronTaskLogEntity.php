<?php
namespace Test\Module\Cron\Entity;

use Swoolefy\Library\Db\Concern\SoftDelete;
use Swoolefy\Library\Db\Query;
use Test\Model\ClientModel;

// 生成的表【cron_task_log】的属性
/**
 * @property int id
 * @property int cron_id 关联的cron_task_id
 * @property string exec_batch_id 每轮执行的批次id
 * @property int pid 执行进程 PID
 * @property int status 执行状态：0-register（注册定时任务） 1-running 2-success 3-failed 4-skipped 5-timeout 6-cancelled 7-unregister
 * @property int trigger_type 触发类型：1-scheduler 2-run_once
 * @property string|null scheduled_at 计划执行时间
 * @property string|null started_at 实际开始执行时间
 * @property string|null finished_at 实际结束执行时间
 * @property int duration_ms 执行耗时毫秒
 * @property int|null exit_code Shell 退出码
 * @property int|null http_status HTTP 响应状态码
 * @property string task_item json类型-执行任务项meta信息
 * @property string message 运行态记录信息（人类可读）
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
        'status' => 'int',
        'trigger_type' => 'int',
        'duration_ms' => 'int',
    ];

    /**
     * 统计 / 列表用：排除已软删行。
     *
     * Query::select() 不会自动加 SoftDelete 条件。
     */
    public static function queryNotDeleted(): Query
    {
        return static::query()->whereDeletedAtNull(static::getSoftDeleteField());
    }

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
