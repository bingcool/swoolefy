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

namespace Swoolefy\Core\Crontab;

use Cron\CronExpression;
use Swoolefy\Core\Process\ProcessController;

abstract class AbstractCronController extends ProcessController
{

    /**
     * $cron_next_datetime 定时任务下一次执行时间
     * @var array
     */
    protected static $cronNextDatetime = [];

    /**
     * $offset_second 偏差时间1s
     * @var integer
     */
    protected static $offsetSecond = 1;

    /**
     * $expression
     * @var array
     */
    protected static $expression = [];

    /**
     * runCron
     * @param string $expression cron的表达式
     * @param callable $func 闭包函数
     * @param string $cron_name
     * @return void
     */
    public function runCron(string $cron_name, string $expression, ?callable $func = null)
    {
        $expression_key = md5($expression);
        /**@var CronExpression $cron */
        if(method_exists(CronExpression::class,'factory')) {
            $cron = CronExpression::factory($expression);
        }else {
            $cron = new CronExpression($expression);
        }

        $now_time = time();
        $cron_next_datetime = strtotime($cron->getNextRunDate()->format('Y-m-d H:i:s'));
        if ($cron->isDue()) {
            if (!isset(static::$cronNextDatetime[$cron_name][$expression_key])) {
                static::$expression[$cron_name][$expression_key] = $expression;
                static::$cronNextDatetime[$cron_name][$expression_key] = $cron_next_datetime;
            }
            if (($now_time >= static::$cronNextDatetime[$cron_name][$expression_key] && $now_time < ($cron_next_datetime - static::$offsetSecond))) {
                static::$cronNextDatetime[$cron_name][$expression_key] = $cron_next_datetime;
                if ($func instanceof \Closure) {
                    call_user_func($func, $cron_name, $expression);
                } else {
                    $this->doCronTask($cron, $cron_name);
                }
                if (!$this->isDefer()) {
                    $this->end();
                }
            }

            if ($now_time > static::$cronNextDatetime[$cron_name][$expression_key] || $now_time >= $cron_next_datetime) {
                static::$cronNextDatetime[$cron_name][$expression_key] = $cron_next_datetime;
            }
        }
    }

    /**
     * @return int
     */
    public function getOffsetSecond()
    {
        return static::$offsetSecond;
    }

    /**
     * @param CronExpression $cron
     * @param string $cron_name
     * @return mixed
     */
    abstract public function doCronTask(CronExpression $cron, string $cron_name);
}