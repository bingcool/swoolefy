<?php
namespace App\Process;

use Swoole\Process;
use Swoolefy\Core\Swfy;
use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Core\Process\ProcessManager;

class Test extends AbstractProcess {
	// 
	public function run(Process $process) {
        // 协议层配置
        // $conf = Swfy::getConf();
        // var_dump($conf);

        // 应用层配置，在自定义进程中只能include
        // $config_path = dirname(__DIR__);
        // $appconf = include_once $config_path.'/Config/config.php';

        // 创建mysql访问对象
        // $db = Swfy::createComponent('db', $appconf['components']['db']);
        // var_dump($db);
        
        var_dump('this is '.$this->getProcessName().' process tick');
    }

    public function onShutDown() {
        // TODO: Implement onShutDown() method.
    }

    public function onReceive($str, ...$args)
    {
        // TODO: Implement onReceive() method.
        var_dump('process rec'.$str);
        
    }
}