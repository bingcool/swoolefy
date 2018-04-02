<?php
namespace App\Process;

use Swoole\Process;
use Swoolefy\Core\Swfy;
use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Core\Process\ProcessManager;

class Test extends AbstractProcess {
	// 
	public function run(Process $process)
    {
       var_dump('this is '.$this->getProcessName().' process tick');
    }

    public function onShutDown()
    {
        // TODO: Implement onShutDown() method.
    }

    public function onReceive(string $str, ...$args)
    {
        // TODO: Implement onReceive() method.
        var_dump('process rec'.$str);
        
    }
}