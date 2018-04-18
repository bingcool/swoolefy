<?php
namespace App\Process\TestProcess;

use Swoole\Process;
use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;
use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Core\Process\ProcessManager;
use Swoolefy\Core\Process\SwooleProcess;
use Swoolefy\Core\Timer\TickManager;

class Test extends AbstractProcess {

    public $SwooleProcessHander;

	public function run(Process $process) {
        // 协议层配置
        // $conf = Swfy::getConf();
        // var_dump($conf);

        // 应用层配置，在自定义进程中只能include
        $config_path = dirname(dirname(__DIR__));
        include_once $config_path.'/Config/defines.php';
        $appconf = include_once $config_path.'/Config/config.php';
        // 创建进程单例应用
        $this->SwooleProcessHander = new SwooleProcess($appconf);   
    }

    public function onReceive($str, ...$args) {
        // 测试退出进程，退出后，底层重新拉起一个新的进程
        // $process = $this->getProcess();
        // $process->kill($process->pid, SIGTERM);
        // $process->wait();
        $this->SwooleProcessHander->end();

    }

    public function onShutDown() {}

    public function __get($name) {
        return Application::$app->$name;
    }
}