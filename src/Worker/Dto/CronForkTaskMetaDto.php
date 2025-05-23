<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

namespace Swoolefy\Worker\Dto;

use Swoolefy\Core\Schedule\ScheduleEvent;
use Swoolefy\Worker\Cron\CronForkProcess;

class CronForkTaskMetaDto extends AbstractDto
{

    const RUN_TYPE = 'swoolefy';

    const CRON_META_ORIGIN_DB = 'db';

    const CRON_META_ORIGIN_YAML = 'yaml';

    const CRON_META_ORIGIN_PHP = 'php';

    /**
     * db cron task Meta配置模式下的数据库的任务id
     *
     * @var int
     */
    public $cron_task_id = 0;

    /**
     * db cron task Meta配置模式下的日志入库类
     *
     * @var string
     */
    public $cron_db_log_class = '';

    /**
     * 计划任务名称
     *
     * @var string
     */
    public $cron_name = '';

    /**
     * 计划任务表达式
     * @var string
     */
    public $cron_expression = '';

    /**
     * 执行的bin二进制文件
     * @var string
     */
    public $exec_bin_file = '';

    /**
     * 执行的脚本
     * @var string
     */
    public $exec_script = '';

    /**
     * swoolefy的script脚本值必须设置为swoolefy
     *
     * @var string
     */
    public $run_type = self::RUN_TYPE;

    /**
     * 是否阻塞执行
     * @var bool
     */
    public $with_block_lapping = false;

    /**
     * 执行的参数
     * @var array
     */
    public $argv = [];

    /**
     * @var string
     */
    public $description = '';

    /**
     * 扩展参数
     * @var array
     */
    public $extend = [];

    /**
     * @var string
     */
    public $output = '/dev/null';

    /**
     * @var string
     */
    public $fork_type = CronForkProcess::FORK_TYPE_PROC_OPEN;

    /**
     * cron模式下固定以守护进程跑脚本
     *
     * @var int
     */
    public $daemon = 1;

    /**
     * @var \Closure
     */
    public $fork_success_callback = '';

    /**
     * 暂不support setting fork_fail_callback 闭包函数
     * @var \Closure
     */
    public $fork_fail_callback = '';

    /**
     * @var array
     */
    public $cron_between = [];

    /**
     * @var array
     */
    public $cron_skip = [];

    /**
     *
     * @param array $taskItem
     * @return ScheduleEvent
     */
    public static function load(array $taskItem)
    {
        $scheduleTask = new ScheduleEvent();
        foreach ($taskItem as $property => $value) {
            $scheduleTask->$property = $value;
        }
        return $scheduleTask;
    }
}