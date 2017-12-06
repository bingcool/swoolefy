<?php

if(isset($argv[1]) && ($argv[1] == '-d' || $argv[1] == '-D')) {
	swoole_process::daemon(true,false);
}

include_once "../../vendor/autoload.php";

// 设置当前进程的名称
cli_set_process_title("php-autoreload-swoole-server");
// 创建进程服务实例
$daemon = new \Swoolefy\AutoReload\daemon();
// 启动
$daemon->run();


