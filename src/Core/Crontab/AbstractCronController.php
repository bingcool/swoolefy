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
     * @var int
     */
    protected static $offsetSecond = 1;

    /**
     * $expression
     * @var array
     */
    protected static $expression = [];

    /**
     * @param string $cronName
     * @param string $expression
     * @param callable|null $func
     * @return void
     * @throws \Exception
     */
    public function runCron(string $cronName, string $expression, ?callable $func = null)
    {
        $expressionKey = md5($expression);
        /**@var CronExpression $cron */
        if(method_exists(CronExpression::class,'factory')) {
            $cron = CronExpression::factory($expression);
        }else {
            $cron = new CronExpression($expression);
        }

        $nowTime = time();
        $cronNextDatetime = strtotime($cron->getNextRunDate()->format('Y-m-d H:i:s'));
        if ($cron->isDue()) {
            if (!isset(static::$cronNextDatetime[$cronName][$expressionKey])) {
                static::$expression[$cronName][$expressionKey] = $expression;
                static::$cronNextDatetime[$cronName][$expressionKey] = $cronNextDatetime;
            }
            if (($nowTime >= static::$cronNextDatetime[$cronName][$expressionKey] && $nowTime < ($cronNextDatetime - static::$offsetSecond))) {
                static::$cronNextDatetime[$cronName][$expressionKey] = $cronNextDatetime;
                if ($func instanceof \Closure) {
                    call_user_func($func, $cronName, $expression);
                } else {
                    $this->doCronTask($cron, $cronName);
                }

                if (!$this->isDefer()) {
                    $this->end();
                }
            }

            if ($nowTime > static::$cronNextDatetime[$cronName][$expressionKey] || $nowTime >= $cronNextDatetime) {
                static::$cronNextDatetime[$cronName][$expressionKey] = $cronNextDatetime;
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
     * @param CronExpression|float $cron
     * @param string $cronName
     * @return mixed
     */
    abstract public function doCronTask($cron, string $cronName);
}