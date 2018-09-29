<?php
/**
+----------------------------------------------------------------------
| swoolefy framework bases on swoole extension development, we can use it easily!
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
+----------------------------------------------------------------------
*/

$monitor_pid = __DIR__.'/monitor.pid';

function commandParser() {
    global $argv;
    $command = isset($argv[1]) ? $argv[1] : null;
    $server = isset($argv[2]) ? $argv[2] : null;
    return ['command'=>$command, 'server'=>$server];
}

function commandHandler() {
    $command = commandParser();
    if(isset($command['server']) && $command['server'] != 'help') {
        switch($command['command']){
            case "start":{
                start();
                break;
            }
            case 'stop':{
                stop();
                break;
            }
            case 'help':
            default:{
                help($command['command']);
            }
        }
    }else {
        help($command['command']);
    }   
}

function start() {
	global $argv;
	if(isset($argv[3]) && ($argv[3] == '-d' || $argv[3] == '-D')) {
		swoole_process::daemon(true,false);
        // 将当前进程绑定至CPU0上
        swoole_process::setaffinity([0]);
	}

	$pid = posix_getpid();
	global $monitor_pid;
	@file_put_contents($monitor_pid, $pid);

	include_once "../../vendor/autoload.php";
    $config = include_once "./config.php";
	// 设置当前进程的名称
	cli_set_process_title("php-autoreload-swoole-server");
	// 创建进程服务实例
	$daemon = new \Swoolefy\AutoReload\Daemon($config = []);
	// 启动
	$daemon->run();
}

function stop() {
	global $monitor_pid;
	$pid = @file_get_contents($monitor_pid);
	if($pid) {
		if(!swoole_process::kill($pid, 0)){
       		echo "warning: pid={$pid} not exist \n";
        	return;
    	}

    	// 发送信号，终止进程
    	swoole_process::kill($pid, SIGTERM);
    	// 回收master创建的子进程（manager,worker,taskworker）
    	swoole_process::wait();
    	//等待2秒
	    $nowtime = time();
	    while(true) {
	        usleep(1000);
	        if(!swoole_process::kill($pid,0)){
	            echo "------------stop info------------\n";
	            echo "successful: auto monitor process stop at ".date("Y-m-d H:i:s")."\n";
	            echo "\n";
	            @unlink($monitor_pid);
	            break;
	        }else {
	            if(time() - $nowtime > 2){
	                echo "-----------stop info------------\n";
	                echo "warnning: stop auto monitor process failed. please try again \n";
	                echo "\n";
	                break;
	            }
	        }
	    }  
	}
	
}

function help($command) {
    switch(strtolower($command.'-'.'help')) {
        case 'start-help':{
            echo "------------swoolefy启动服务命令------------\n";
            echo "1、执行php start.php start monitor 即在当前终端启动monitor服务\n";
            echo "2、执行php start.php start monitor -d 即以守护进程启动monitor服务\n";
            echo "\n";
            break;
        }
        case 'stop-help':{
            echo "------------swoolefy终止服务命令------------\n";
            echo "1、执行php start.php stop monitor 即可终止monitor服务\n";
            echo "\n";
            break;
        }
        default:{
            echo "------------欢迎使用swoolefy------------\n";
            echo "有关某个命令的详细信息，请键入 help 命令:\n";
            echo "1、php start.php start help 查看详细信息!\n";
            echo "2、php start.php stop help 查看详细信息!\n";
            echo "\n";
        }
    }
}

commandHandler();



