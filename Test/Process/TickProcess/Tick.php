<?php
namespace Test\Process\TickProcess;

use Swoole\Process;
use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;
use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Core\Process\ProcessManager;
use Swoolefy\Core\Timer\TickManager;


class Tick extends AbstractProcess {

    public $swooleProcessHander;

    public function run() {
        // 协议层配置
        // $conf = Swfy::getConf();
        goApp(function() {
            $cid = \Swoole\Coroutine::getCid();
        });

        var_dump('This is process tick, class='.__CLASS__);
        // 创建定时器处理实例
        TickManager::getInstance()->tickTimer(3000,
            [TickController::class, 'tickTest'],
            ['name'=>'swoolefy-tick']
        );
    }

    public function onReceive($str, ...$args)
    {

    }

    public function onShutDown() {}

    public function __get($name) {
        return Application::getApp()->$name;
    }
}