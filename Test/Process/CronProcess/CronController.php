<?php
namespace Test\Process\CronProcess;

use Cron\CronExpression;
use Swoolefy\Core\Crontab\AbstractCronController;
use Test\App;

class CronController extends AbstractCronController {

    /**
     * @inheritDoc
     */
    public function doCronTask($cron, string $cronName)
    {
        if($cron instanceof CronExpression) {
            $expression = $cron->getExpression();
            $redis = App::getRedis();
            $redis->set('key','key-id='.rand(1,1000));
            $keyValue = $redis->get('key');
            var_dump("This is Crontab process, keyValue={$keyValue}, expression={$expression}, class=".__CLASS__);
        }else {
//            goApp(function () {
//                $result = App::getDb()->newQuery()->table('tbl_users')->where(['user_id' => 10000])->find();
//                var_dump($result);
//            });
//
//            goApp(function () {
//                $result = App::getDb()->newQuery()->table('tbl_users')->where(['user_id' => 46428])->order('')->find();
//                var_dump($result);
//            });

            var_dump("This is Crontab process, tick loop cron");
        }
    }
}