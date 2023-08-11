<?php
namespace Test\Process\CronProcess;

use co;
use Swoole\Process;
use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;
use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Core\Process\ProcessManager;
use Swoolefy\Core\Timer\TickManager;
use Swoolefy\Core\Crontab\CrontabManager;

class Cron extends AbstractProcess {

    private $appConf;

    public function run() {
        try {
            $this->appConf = Swfy::getAppConf();
            // 闭包回调模式
//            CrontabManager::getInstance()->addRule('cron_test', '*/1 * * * *', function($cron) {
//                $cid = \Swoole\Coroutine::getCid();
//                $date = date('Y-m-d H:i:s');
//                var_dump('This is Cron Process Cid='.$cid.', now date='.$date.', class='.__CLASS__);
//                sleep(5);
//            });
//
//            // 抽离成CronController形式
            CrontabManager::getInstance()->addRule('cron_test1', '*/1 * * * *', [CronController::class, 'doCronTask']);

//            CrontabManager::getInstance()->addRule('cron_test1', '*/1 * * * *', [CronController::class, 'doCronTask']);

//            CrontabManager::getInstance()->addRule('cron_test1', 2, function ()  {
//                $redis = Application::getApp()->get('redis');
//            });


        }catch (\Throwable $e)
        {
            throw $e;
        }

    }

    public function onReceive($str, ...$args) {

    }

    public function onShutDown() {

    }

}