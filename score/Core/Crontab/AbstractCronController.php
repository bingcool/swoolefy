<?php
/**
+----------------------------------------------------------------------
| swoolefy framework bases on swoole extension development, we can use it easily!
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
+----------------------------------------------------------------------
*/

namespace Swoolefy\Core\Crontab;

use Cron\CronExpression;
use Swoolefy\Core\Process\ProcessController;

abstract class AbstractCronController extends ProcessController {

	/**
	 * $cron_next_datetime 定时任务下一次执行时间
	 * @var null
	 */
	protected static $cron_next_datetime = [];

	/**
     * $offset_second 偏差时间1s
     * @var integer
     */
    protected static $offset_second = 1;

    /**
     * $expression
     * @var null
     */
    protected static $expression = [];

    /**
     * runCron 
     * @param  string  $expression cron的表达式
     * @param  callable $func   闭包函数
     * @return void
     */
    public function runCron(string $expression, $func = null) {
    	$expression_key = md5($expression);
    	$cron = CronExpression::factory($expression);
        $now_time = time();   
        $cron_next_datetime = strtotime($cron->getNextRunDate()->format('Y-m-d H:i:s'));
        if($cron->isDue()) {
        	if(!isset(static::$cron_next_datetime[$expression_key])) {
        		static::$expression[$expression_key] = $expression;
            	static::$cron_next_datetime[$expression_key] = $cron_next_datetime;
        	}

        	if(($now_time >= static::$cron_next_datetime[$expression_key] && $now_time < ($cron_next_datetime - static::$offset_second))) {
	            static::$cron_next_datetime[$expression_key] = $cron_next_datetime;
                if($func instanceof \Closure) {
                    //call_user_func_array($func->bindTo($this, get_class($this)), [$cron]);
                    $func->call($this, $cron);
                }else {
                    $this->doCronTask($cron);
                }
                if(!$this->isDefer()) {
                    $this->end();
                }
        	}

        	// 防止万一出现的异常出现，比如没有命中任务， 19:05:00要命中的，由于其他网络或者服务器其他原因，阻塞了,造成延迟，现在时间已经到了19::05:05
	        if($now_time > static::$cron_next_datetime[$expression_key] || $now_time >= $cron_next_datetime) {
	            static::$cron_next_datetime[$expression_key] = $cron_next_datetime;
	        }
        }
    }

    public function getOffsetSecond() {
    	return static::$offset_second;
    }

    public abstract function doCronTask(CronExpression $cron);
}