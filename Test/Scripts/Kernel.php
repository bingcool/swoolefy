<?php

namespace Test\Scripts;

use Swoolefy\Core\Schedule\Schedule;
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
     * @return Schedule
     */
    public static function schedule()
    {
        $schedule = Schedule::getInstance();
        $schedule->command(User\FixedUser::command)
            ->cron(10)
            ->addArgs('name', 'bingcool')
            ->addArgs('age', 18)
            ->addArgs('sex', 'man')
            ->addArgs('desc', "fff kkkmm")
            ->forkType(CronForkProcess::FORK_TYPE_PROC_OPEN);

//        $schedule->command(User\FixedUser::command)
//            ->everyMinute()
//            ->addArgs('name', 'bingcool')
//            ->addArgs('age', 18)
//            ->addArgs('sex', 'man')
//            ->addArgs('desc', "fff kkkmm")
//            ->forkType(CronForkProcess::FORK_TYPE_PROC_OPEN);

        return $schedule;
    }


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