<?php

namespace Test\Scripts;

use Swoolefy\Core\SystemEnv;
use Swoolefy\Worker\Cron\CronForkProcess;

class Kernel
{
    public static $commands = [
        GenerateMysql::command => [GenerateMysql::class, 'generate'],
        GeneratePg::command    => [GeneratePg::class, 'generate'],
        User\FixedUser::command => [User\FixedUser::class, 'fixName']
    ];

    /**
     * 任务调度配置
     *
     * @var array[]
     */
    public static $schedule = [
//        [
//            'command' => User\FixedUser::command,
//            //'cron_expression' => 10, // 10s执行一次
//            'cron_expression' => '*/1 * * * *', // 每分钟执行一次
//            'desc' => '',
//        ],
        [
            'command' => User\FixedUser::command,
            'fork_type' => CronForkProcess::FORK_TYPE_PROC_OPEN,
            'cron_expression' => 20, // 10s执行一次
            //'cron_expression' => '*/1 * * * *', // 每分钟执行一次
            'argv' => [
                'name' => 'bingcool',
                'age' => 18,
                'sex' => 'man',
                'desc' => "fff kkkmm"
            ],
            'desc' => '',
        ],
    ];


    /**
     * 配置化调度
     *
     * @return array
     */
    public static function buildScheduleTaskList()
    {
        $appName = $_SERVER['argv'][2];
        $scheduleList = [];
        foreach (self::$schedule as $item) {
            $item['cron_name'] = $item['command'].'-'.$item['cron_expression'];
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
            $argv = implode(' ', $argvOptions);
            $item['exec_script'] = "script.php start {$appName} --c={$item['command']} $argv";
            $item['params'] = [];
            $scheduleList[] = $item;
        }
        return $scheduleList;
    }


}