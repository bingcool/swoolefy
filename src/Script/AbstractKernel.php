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

    const OPTION_SCHEDULE_MODEL = '--schedule_model';

    const OPTION_SCHEDULE_CRON_SCRIPT_PID_FILE = '--cron_script_pid_file';

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
        $appName      = APP_NAME;
        $scheduleList = [];

        foreach ($schedule->toArray() as $item) {
            if (empty($item['exec_bin_file'])) {
                $item['exec_bin_file'] = SystemEnv::PhpBinFile();
            }

            if (empty($item['fork_type'])) {
                $item['fork_type'] = CronForkProcess::FORK_TYPE_PROC_OPEN;
            }

            if (empty($item['argv'])) {
                $item['argv'] = [];
            }
            $item['argv']['daemon'] = $item['daemon'] ?? 1;
            $argvOptions = [];
            foreach ($item['argv'] as $argvName=>$argvValue) {
                if (str_contains($argvValue, ' ')) {
                    $argvOptions[] = "--{$argvName}='{$argvValue}'";
                }else {
                    $argvOptions[] = "--{$argvName}={$argvValue}";
                }
            }
            // cron模式
            $scheduleModel = self::OPTION_SCHEDULE_MODEL;
            $argvOptions[] = "{$scheduleModel}=cron";

            $argv = implode(' ', $argvOptions);
            if (empty($item['cron_name'])) {
                if (str_contains($item['cron_expression'], ' ')) {
                    $cron_expression = '\''.$item["cron_expression"].'\'';
                }else {
                    $cron_expression = $item["cron_expression"];
                }
                // cron_name 唯一
                $item['cron_name'] = ($item['command'] ?? 'schedule').' --cron_expression='.$cron_expression.' '.$argv;
            }

            $command  = $item['command'];
            if (empty($item['exec_script'])) {
                $item['exec_script'] = "script.php start {$appName} --c={$command}";
            }
            $item['argv'] = $argvOptions;
            unset($item['command']);
            $scheduleList[] = $item;
        }
        return $scheduleList;
    }

    public static function getScheduleModelOptionField()
    {
        return str_replace("--","", self::OPTION_SCHEDULE_MODEL);
    }

    public static function getCronScriptPidFileOptionField()
    {
        return str_replace("--","", self::OPTION_SCHEDULE_CRON_SCRIPT_PID_FILE);
    }

    public static function getCronScriptFullPidFile($fileName)
    {
        $cronScriptPidDir = WORKER_PID_FILE_ROOT.'/cron_script';
        if (!is_dir($cronScriptPidDir)) {
            mkdir($cronScriptPidDir, 0777, true);
        }
        return $cronScriptPidDir.'/'.$fileName;
    }


}