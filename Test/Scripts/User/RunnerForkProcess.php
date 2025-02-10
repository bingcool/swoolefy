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
//        $status = $runner->procOpen("nohup /bin/bash","/home/wwwroot/swoolefy/Test/Python/shell.sh &", [], function($pipe0, $pipe1, $pipe2, $status) {
//            var_dump($status);
//        });

        $status = $runner->procOpen("ps -eo pid,cmd | awk '{print $1}'","", [], function($pipe0, $pipe1, $pipe2, $status) {
//            while ($output = fgets($pipe1)) {
//                var_dump(trim($output));
//            }
        });

        goApp(function() use($runner) {
            \Co\defer(function() {
                var_dump("defer");
            });
            $runner->exec(
                "/bin/bash",
                "/home/wwwroot/swoolefy/Test/Python/shell.sh",
                [],
                true,
                "/dev/null"
            );
        });

        var_dump('end');

//        list($command, $output, $returnCode, $pid) = $runner->exec(
//            "ps -eo pid,cmd | awk '{print $1}'",
//            "",
//            [],
//            false,
//            ""
//        );

        //var_dump($command, $output, $returnCode, $pid);
    }
}