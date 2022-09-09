<?php

define('IS_WORKER_SERVICE', 1);
date_default_timezone_set('Asia/Shanghai');
define('WORKER_START_SCRIPT_FILE', $_SERVER['PWD'].'/'.$_SERVER['SCRIPT_FILENAME']);
define('WORKER_PID_FILE_ROOT', '/tmp/workerfy/log/test-worker');
define('WORKER_PID_FILE', WORKER_PID_FILE_ROOT.'/worker.pid');
define('WORKER_STATUS_FILE',WORKER_PID_FILE_ROOT.'/status.txt');
define('WORKER_CTL_LOG_FILE',WORKER_PID_FILE_ROOT.'/ctl.txt');
define('WORKER_APP_ROOT', __DIR__.'/Test/workerDaemon');

define('APP_NAMES', [
    'Test' => 'http'
]);

include './swoolefy';