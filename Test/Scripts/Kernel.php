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

namespace Test\Scripts;

use Swoolefy\Core\Schedule\ScheduleEvent;
use Swoolefy\Script\AbstractKernel;
use Swoolefy\Script\GenerateMysql;
use Swoolefy\Script\GeneratePg;
use Swoolefy\Script\GenerateCronService;
use Swoolefy\Script\GenerateDaemonService;
use Swoolefy\Script\TestScript;
use Swoolefy\Core\Schedule\Schedule;
use Test\Scripts\User;

class Kernel extends AbstractKernel
{
    /**
     * script
     * @Description  script commands
     *
     * @var array
     */
    public static $commands = [
        GenerateMysql::command => [GenerateMysql::class, 'handle'],
        GeneratePg::command    => [GeneratePg::class, 'handle'],
        GenerateCronService::command  => [GenerateCronService::class, 'handle'],
        GenerateDaemonService::command  => [GenerateDaemonService::class, 'handle'],
        TestScript::command    => [TestScript::class, 'handle'],
        User\FixedUser::command => [User\FixedUser::class, 'handle'],

        User\RunnerForkProcess::command => [User\RunnerForkProcess::class, 'handle'],
        User\Purl::command => [User\Purl::class, 'handle'],
        User\TestPgQuery::command => [User\TestPgQuery::class, 'handle'],
    ];

    /**
     * @return Schedule
     */
    public static function schedule()
    {
        $schedule = Schedule::getInstance();

//        $schedule->command(\Swoolefy\Script\TestScript::command)
//            ->cron(15)
//            ->withBlockLapping();

        $schedule->command(User\FixedUser::command)
            ->cron(5)
            ->addArgs('name', 'bingcool')
            ->addArgs('age', 18)
            ->addArgs('sex', 'man')
            ->addArgs('desc', "fffkkkmm")
            ->withBlockLapping()
            ->ForkSuccessCallback(function(ScheduleEvent $event) {
                var_dump("fork successful!");
            });

        return $schedule;
    }

}