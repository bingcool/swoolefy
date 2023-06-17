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
define('WORKER_STATUS_FILE', WORKER_PID_FILE_ROOT.'/status.log');
define('WORKER_CTL_LOG_FILE', WORKER_PID_FILE_ROOT.'/ctl.log');
define('WORKER_CLI_PIPE', WORKER_PID_FILE_ROOT.'/cli.pipe');
define('WORKER_CTL_PIPE', WORKER_PID_FILE_ROOT.'/ctl.pipe');

define('WORKER_APP_ROOT', __DIR__.'/Test/workerDaemon');
date_default_timezone_set('Asia/Shanghai');

// script 为空即可
define('PROCESS_CLASS', []);

define('APP_NAMES', [
    'Test' => 'http'
]);

include './swoolefy';