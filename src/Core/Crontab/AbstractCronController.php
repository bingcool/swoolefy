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

declare(strict_types=1);

namespace Swoolefy\Core\Crontab;

use Cron\CronExpression;
use Swoolefy\Core\Process\ProcessController;

abstract class AbstractCronController extends ProcessController
{

    /**
     * $cronLastRunSlot 每个任务最近一次已执行的触发时刻（分钟起点的时间戳）
     * @var array
     */
    protected static $cronLastRunSlot = [];

    /**
     * $offsetSecond 偏差时间1s
     * @var int
     */
    protected static $offsetSecond = 1;

    /**
     * $expression
     * @var array
     */
    protected static $expression = [];

    /**
     * 当前进程开始调度的分钟起点，按 pid 记录，fork 出的子进程会重新取值
     * @var array{pid:int, minute:int}|null
     */
    private static ?array $schedulerBoot = null;

    /**
     * 由 CrontabManager 每 2 秒调用一次。同一触发时刻（分钟）只执行一次。
     *
     * 进程启动所在的那一分钟不执行：进程按 life_time 重启时，新进程可能落在旧进程刚执行过的同一分钟里，
     * 跳过这一分钟才不会重复执行。之后的每个触发时刻都会执行，包括启动后的第一个。
     *
     * @param string $cronName
     * @param string $expression
     * @param callable|null $func
     * @return void
     */
    public function runCron(string $cronName, string $expression, ?callable $func = null)
    {
        $expressionKey = md5($expression);
        /**@var CronExpression $cron */
        if (method_exists(CronExpression::class,'factory')) {
            $cron = CronExpression::factory($expression);
        } else {
            $cron = new CronExpression($expression);
        }

        $now = new \DateTime('now');
        $bootMinute = self::schedulerBootMinute($now->getTimestamp());
        if (!$cron->isDue($now)) {
            return;
        }

        static::$expression[$cronName][$expressionKey] = $expression;
        $slot = $cron->getPreviousRunDate($now, 0, true)->getTimestamp();
        $lastSlot = static::$cronLastRunSlot[$cronName][$expressionKey] ?? null;

        $runOnceCron = getenv('run-once-cron');
        $shouldRun = ($runOnceCron == 1) || ($slot !== $lastSlot && $slot > $bootMinute);
        if (!$shouldRun) {
            return;
        }

        putenv('run-once-cron=0');
        // 执行前先记下触发时刻，任务耗时超过 2 秒时下一次 tick 不会再进来
        static::$cronLastRunSlot[$cronName][$expressionKey] = $slot;
        if ($func instanceof \Closure) {
            call_user_func($func, $cronName, $expression);
        } else {
            $this->doCronTask($cron, $cronName);
            $this->afterHandle();
        }

        if (!$this->isDefer()) {
            $this->end();
        }
    }

    /**
     * @param int $nowTime
     * @return int
     */
    private static function schedulerBootMinute(int $nowTime): int
    {
        $pid = getmypid();
        if (self::$schedulerBoot === null || self::$schedulerBoot['pid'] !== $pid) {
            self::$schedulerBoot = ['pid' => $pid, 'minute' => $nowTime - ($nowTime % 60)];
        }
        return self::$schedulerBoot['minute'];
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
    abstract public function doCronTask(CronExpression|float $cron, string $cronName);
}