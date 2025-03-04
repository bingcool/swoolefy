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

use Swoolefy\Script\AbstractKernel;
use Swoolefy\Script\GenerateMysql;
use Swoolefy\Script\GeneratePg;
use Swoolefy\Script\GenerateCronService;
use Swoolefy\Script\GenerateDaemonService;
use Swoolefy\Script\TestScript;
use Swoolefy\Core\Schedule\Schedule;
use Swoolefy\Worker\Cron\CronForkProcess;

class Kernel extends AbstractKernel
{
    /**
     * script
     * @Description  script commands
     *
     * @var array
     */
    public static $commands = [
        GenerateMysql::command         => [GenerateMysql::class, 'handle'],
        GeneratePg::command            => [GeneratePg::class, 'handle'],
        GenerateCronService::command   => [GenerateCronService::class, 'handle'],
        GenerateDaemonService::command => [GenerateDaemonService::class, 'handle'],
        TestScript::command            => [TestScript::class, 'handle'],
    ];

    /**
     * @return Schedule
     */
    public static function schedule()
    {
        $schedule = Schedule::getInstance();
        $schedule->command(\Swoolefy\Script\TestScript::command)
            ->cron(10);

        return $schedule;
    }

}