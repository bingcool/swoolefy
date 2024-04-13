<?php

namespace Test\Scripts;

class Kernel
{
    public static $commands = [
        GenerateMysql::command => [GenerateMysql::class, 'generate'],
        GeneratePg::command    => [GeneratePg::class, 'generate'],
        User\FixedUser::command     => [User\FixedUser::class, 'fixName']
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
            'cron_expression' => 3600, // 10s执行一次
            //'cron_expression' => '*/1 * * * *', // 每分钟执行一次
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
            $item['exec_bin_file'] = $_SERVER['_'] ?? '/usr/bin/php';
            $item['fork_type'] = \Swoolefy\Worker\Cron\CronForkProcess::FORK_TYPE_PROC_OPEN;
            $item['exec_script'] = "script.php start {$appName} --c={$item['command']} --daemon=1";
            $item['params'] = [];
            $scheduleList[] = $item;
        }
        return $scheduleList;
    }


}