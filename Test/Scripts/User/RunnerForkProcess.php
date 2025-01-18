<?php
namespace Test\Scripts\User;

use Swoolefy\Core\CommandRunner;
use Swoolefy\Script\MainCliScript;

class RunnerForkProcess extends MainCliScript
{
    const command = 'runner:fork:process';

    public function init()
    {

    }

    public function handle()
    {
        $runner = CommandRunner::getInstance("test",1);
        $runner->isNextHandle(false);
        $status = $runner->procOpen("nohup /bin/bash","/home/wwwroot/swoolefy/Test/Python/shell.sh &", [], function($pipe0, $pipe1, $pipe2, $status) {
//            while ($content = fgets($pipe1)) {
//                var_dump(trim($content));
//            }
            var_dump($status);
        });
    }
}