<?php
include './vendor/autoload.php';

define('IS_DAEMON_SERVICE', 0);
define('IS_CRON_SERVICE', 0);
define('IS_CLI_SCRIPT', 1);

define('WORKER_SERVICE_NAME', makeServerName($_SERVER['argv'][2]));

define('WORKER_PORT', get_one_free_port([9501, 9602, 9603]));
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

// script 为空即可
define('PROCESS_CLASS', []);

// 定义脚本文件夹的根目录
define('ROOT_NAMESPACE', [
    'Test' => '\\Test\\Scripts',
]);

define('APP_NAMES', [
    'Test' => 'http'
]);

// 启动前处理,比如加载.env
$beforeFunc = function () {

};

include './swoolefy';