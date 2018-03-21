<?php

$DIR = __DIR__;

// include composer的自动加载类完成命名空间的注册
include_once $DIR.'/vendor/autoload.php';
// include App应用层的自定义的自动加载类命名空间
include_once $DIR.'/autoloader.php'; 
// include 记载框架的整体定义常量
include_once $DIR.'/score/MPHP.php';

function initCheck(){
    if(version_compare(phpversion(),'7.0.0','<')) {
        die("php version must >= 5.6");
    }
    if(version_compare(swoole_version(),'1.9.15','<')) {
        die("swoole version must >= 1.9.15");
    }
}

function opCacheClear(){
    if(function_exists('apc_clear_cache')){
        apc_clear_cache();
    }
    if(function_exists('opcache_reset')){
        opcache_reset();
    }
}

function commandParser() {
    global $argv;
    $command = isset($argv[1]) ? $argv[1] : null;
    $server = isset($argv[2]) ? $argv[2] : null;
    return ['command'=>$command, 'server'=>$server];
}

function startServer($server) {
    opCacheClear();
    $dir = __DIR__;
	switch(strtolower($server)) {
		case 'http':{
            $path = $dir.'/protocol/http';
            if(!is_dir($path)) {
                @mkdir($path, 0777, true);
            }
            $config_file = $path.'/config.php';
            if(!file_exists($config_file)) {
                copy($dir.'/score/Http/config.php', $config_file);
            }
            $config = include $config_file;
            $http = new \Swoolefy\Http\HttpServer($config);
            $http->start();
            break;
        }
		case 'websocket':{
            $path = $dir.'/protocol/websocket';
            if(!is_dir($path)) {
                @mkdir($path, 0777, true);
            }
            $config_file = $path.'/config.php';
            if(!file_exists($config_file)) {
                copy($dir.'/score/Websocket/config.php', $config_file);
            }
            $config = include $config_file;
			$websocket = new \Swoolefy\Websocket\WebsocketEventServer($config);
            $websocket->start();
            break;
        }
        case 'rpc': {
            $path = $dir.'/protocol/rpc';
            if(!is_dir($path)) {
                @mkdir($path, 0777, true);
            }
            $config_file = $path.'/config.php';
            if(!file_exists($config_file)) {
                copy($dir.'/score/Rpc/config.php', $config_file);
            }
            $config = include $config_file;
            $rpc = new \Swoolefy\Rpc\RpcServer($config);
            $rpc->start();
            break;
        }
        case 'monitor' :{
            global $argv;
            $path = $dir.'/protocol/monitor';
            if(!is_dir($path)) {
                @mkdir($path, 0777, true);
            }

            $config_file = $path.'/config.php';
            if(!file_exists($config_file)) {
                copy($dir.'/score/AutoReload/config.php', $config_file);
            }

            if(isset($argv[3]) && ($argv[3] == '-d' || $argv[3] == '-D')) {
                swoole_process::daemon(true,false);
                // 将当前进程绑定至CPU0上
                // swoole_process::setaffinity([0]);
            }

            $pid = posix_getpid();
            $monitor_pid_file = $path.'/monitor.pid';
            @file_put_contents($monitor_pid_file, $pid);
            // 设置当前进程的名称
            cli_set_process_title("php-autoreload-swoole-server");
            $config = include $config_file;
            // 创建进程服务实例
            $daemon = new \Swoolefy\AutoReload\daemon($config);
            // 启动
            $daemon->run();
        }
        default:{
            help($command='help');
        }
	}
    return ;
}

function stopServer($server) {
    $dir = __DIR__;
	switch(strtolower($server)) {
		case 'http': {
            $path = $dir.'/protocol/http';
            $pid_file = $path.'/server.pid';  
		    break;
        }
		case 'websocket': {
            $path = $dir.'/protocol/websocket';
			$pid_file = $path.'/server.pid';
		    break;
        }
        case 'rpc': {
            $path = $dir.'/protocol/rpc';
            $pid_file = $path.'/server.pid';
            break;
        }
        case 'monitor': {
            $path = $dir.'/protocol/monitor';
            $pid_file = $path.'/monitor.pid';
            break;
        }
        default:{
            help($command='help');
        }
	}

    if(!is_file($pid_file)) {
        echo "warning: pid file {$pid_file} is not exist! \n";
        return;
    }
    $pid = intval(file_get_contents($pid_file));
    if(!swoole_process::kill($pid,0)){
        echo "warning: pid={$pid} not exist \n";
        return;
    }
    // 发送信号，终止进程
    swoole_process::kill($pid,SIGTERM);
    // 回收master创建的子进程（manager,worker,taskworker）
    swoole_process::wait();
    //等待2秒
    $nowtime = time();
    while(true){
        usleep(1000);
        if(!swoole_process::kill($pid,0)){
            echo "------------stop info------------\n";
            echo "successful: server stop at ".date("Y-m-d H:i:s")."\n";
            echo "\n";
            @unlink($pid_file);
            break;
        }else {
            if(time() - $nowtime > 2){
                echo "-----------stop info------------\n";
                echo "warnning: stop server failed. please try again \n";
                echo "\n";
                break;
            }
        }
    }  
}

function help($command) {
    switch(strtolower($command.'-'.'help')) {
        case 'start-help':{
            echo "------------swoolefy启动服务命令------------\n";
            echo "1、执行php start.php start http 即可启动http server服务\n\n";
            echo "2、执行php start.php start websocket 即可启动websocket server服务\n\n";
            echo "3、执行php start.php start rpc 即可启动rpc server服务\n\n";
            echo "4、执行php start.php start monitor 即在当前终端启动monitor 监控文件自动重启worker服务\n\n";
            echo "5、执行php start.php start monitor -d 即以守护进程启动monitor 监控文件自动重启worker服务\n\n";
            echo "\n";
            break;
        }
        case 'stop-help':{
            echo "------------swoolefy终止服务命令------------\n";
            echo "1、执行php start.php stop http 即可终止http server服务\n\n";
            echo "2、执行php start.php stop websocket 即可终止websocket server服务\n\n";
            echo "3、执行php start.php stop rpc 即可终止rpc server服务\n\n";
            echo "4、执行php start.php stop monitor 即可终止monitor 监控文件自动重启worker服务\n\n";
            echo "\n";
            break;
        }
        default:{
            echo "------------欢迎使用swoolefy------------\n";
            echo "有关某个命令的详细信息，请键入 help 命令:\n";
            echo "1、php start.php start help 查看详细信息!\n\n";
            echo "2、php start.php stop help 查看详细信息!\n\n";
        }
    }
}

function commandHandler(){
    $command = commandParser();
    if(isset($command['server']) && $command['server'] != 'help') {
        switch($command['command']){
            case "start":{
                startServer($command['server']);
                break;
            }
            case 'stop':{
                stopServer($command['server']);
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

initCheck();
commandHandler();