<?php

include './vendor/autoload.php';
registerNamespace($_SERVER['argv'][2]);

define('WORKER_PORT', 9603);
define('IS_DAEMON_SERVICE', 0);
define('IS_CRON_SERVICE', 1);
define('IS_CLI_SCRIPT', 0);

define('WORKER_SERVICE_NAME', makeServerName($_SERVER['argv'][2]));

define('WORKER_START_SCRIPT_FILE', $_SERVER['PWD'].'/'.$_SERVER['SCRIPT_FILENAME']);
define('WORKER_PID_FILE_ROOT', '/tmp/workerfy/log/'.WORKER_SERVICE_NAME);
define('WORKER_PID_FILE', WORKER_PID_FILE_ROOT.'/worker.pid');
define('WORKER_STATUS_FILE',WORKER_PID_FILE_ROOT.'/status.log');
define('WORKER_CTL_LOG_FILE',WORKER_PID_FILE_ROOT.'/ctl.log');
define('CLI_TO_WORKER_PIPE',WORKER_PID_FILE_ROOT.'/cli.pipe');
define('WORKER_TO_CLI_PIPE',WORKER_PID_FILE_ROOT.'/ctl.pipe');
// 应用父目录
defined('ROOT_PATH') or define('ROOT_PATH', __DIR__);
// 启动目录
defined('START_DIR_ROOT') or define('START_DIR_ROOT', __DIR__);

date_default_timezone_set('Asia/Shanghai');

define('WORKER_CONF', \Swoolefy\Worker\MainManager::loadConfByPath(__DIR__.'/'.$_SERVER['argv'][2].'/WorkerCron/worker_cron_conf.php'));

define('PROCESS_CLASS', [
    // 应用crom worker
    'Test' => \Test\WorkerCron\MainCronProcess::class,
]);

define('APP_NAMES', [
    'Test' => 'http'
]);

// 启动前处理,比如加载.env
$beforeFunc = function () {

};

include './swoolefy';