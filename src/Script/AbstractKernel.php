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

namespace Swoolefy\Script;

use Swoolefy\Core\Schedule\Schedule;
use Swoolefy\Core\SystemEnv;
use Swoolefy\Worker\Cron\CronForkProcess;

abstract class AbstractKernel {

    public static $commands = [];

    public static function getCommands()
    {
        return static::$commands;
    }

	/**
	 * schedule
	 * @return void
	 */
	abstract public static function schedule();

    /**
     * 配置化调度
     *
     * @return array
     */
    public static function buildScheduleTaskList(Schedule $schedule)
    {
        $appName = $_SERVER['argv'][2];
        $scheduleList = [];

        foreach ($schedule->toArray() as $item) {
            $item['exec_bin_file'] = SystemEnv::PhpBinFile();
            if (!isset($item['fork_type'])) {
                $item['fork_type'] = CronForkProcess::FORK_TYPE_PROC_OPEN;
            }

            if (!isset($item['argv'])) {
                $item['argv'] = [];
            }
            $item['argv']['daemon'] = 1;

            $argvOptions = [];
            foreach ($item['argv'] as $argvName=>$argvValue) {
                if (str_contains($argvValue, ' ')) {
                    $argvOptions[] = "--{$argvName}='{$argvValue}'";
                }else {
                    $argvOptions[] = "--{$argvName}={$argvValue}";
                }
            }
            $argvOptions[] = "--schedule_model=cron";
            $argv = implode(' ', $argvOptions);
            $item['exec_script'] = "script.php start {$appName} --c={$item['command']} $argv";
            if (!isset($item['cron_name'])) {
                $item['cron_name'] = $item['command'].'-'.$item['cron_expression'].' '.$argv;
            }
            $item['params'] = [];
            $scheduleList[] = $item;
        }
        return $scheduleList;
    }
}