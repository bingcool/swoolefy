<?php
// include composer的自动加载类完成命名空间的注册
include_once '../vendor/autoload.php';
// include App应用层的自定义的自动加载类命名空间
include_once '../App/autoloader.php';

function initCheck(){
    if(phpversion() < 5.6){
        die("php version must >= 5.6");
    }
    if(phpversion('swoole') < 1.9){
        die("swoole version must >= 1.9.5");
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
	switch(strtolower($server)) {
		case 'http':{
            $http = new \Swoolefy\Http\HttpServer();
            $http->start();
            break;
        }
		case 'websocket':{
			$websocket = new \Swoolefy\Websocket\WebsocketServer();
            $websocket->start();
            break;
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
            $pid_file = $dir.'/Http/server.pid';  
		    break;
        }
		case 'websocket': {
			$pid_file = $dir.'/Websocket/server.pid';
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
            @unlink($pid_file);
            break;
        }else {
            if(time() - $nowtime > 2){
                echo "-----------stop info------------\n";
                echo "warnning: stop server failed. please try again \n";
                break;
            }
        }
    }  
}

function help($command) {
    switch(strtolower($command.'-'.'help')) {
        case 'start-help':{
            echo "------------swoolefy启动服务命令------------\n";
            echo "1、执行php start.php start http 即可启动http server服务\n";
            echo "2、执行php start.php start websocket 即可启动websocket server服务\n\n";
            break;
        }
        case 'stop-help':{
            echo "------------swoolefy终止服务命令------------\n";
            echo "1、执行php start.php stop http 即可终止http server服务\n";
            echo "2、执行php start.php stop websocket 即可终止websocket server服务\n\n";
            break;
        }
        default:{
            echo "------------欢迎使用swoolefy------------\n";
            echo "有关某个命令的详细信息，请键入 help 命令:\n";
            echo "1、php start.php start help 查看详细信息!\n";
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