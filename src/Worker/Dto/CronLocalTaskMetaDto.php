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

class CronLocalTaskMetaDto extends AbstractDto
{
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
     * 任务处理类 必须继承 \Swoolefy\Core\Crontab\AbstractCronController， 并实现doCronTask方法
     * @var string
     */
    public $handler_class = '';

    /**
     * 是否阻塞执行
     * @var bool
     */
    public $with_block_lapping = false;

    /**
     * 定时任务后台运行，不受stop指令影响，正在执行的任务会继续执行，只对cron的local模式有效
     *
     * @var bool
     */
    public $run_in_background = true;

}