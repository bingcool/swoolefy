<?php

namespace Test\Scripts;

use Swoolefy\Core\Schedule\Schedule;
use Swoolefy\Script\AbstractKernel;
use Swoolefy\Worker\Cron\CronForkProcess;
use Test\Scripts\User\TestDbQuery;

class Kernel extends AbstractKernel
{
    public static $commands = [
        GenerateMysql::command => [GenerateMysql::class, 'generate'],
        GeneratePg::command    => [GeneratePg::class, 'generate'],
        User\FixedUser::command => [User\FixedUser::class, 'fixName'],
        Phpy\Py::command => [Phpy\Py::class, 'testPhpy'],
        TestDbQuery::command => [TestDbQuery::class, 'testDbQuery'],
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

}