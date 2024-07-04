<?php

include __DIR__.'/vendor/autoload.php';

$appName = ucfirst($_SERVER['argv'][2]);
// 定义app name
define('APP_NAME', $appName);
// 启动目录
defined('START_DIR_ROOT') or define('START_DIR_ROOT', __DIR__);
// 应用父目录
defined('ROOT_PATH') or define('ROOT_PATH',__DIR__);
// 应用目录
defined('APP_PATH') or define('APP_PATH',__DIR__.'/'.$appName);

registerNamespace(APP_PATH);

define('APP_META_ARR', [
    'Test' => [
        'protocol' => 'http',
        'worker_port' => get_one_free_port([9501, 9602, 9603]),
    ]
]);

define('WORKER_PORT', APP_META_ARR[$appName]['worker_port']);
define('IS_DAEMON_SERVICE', 0);
define('IS_CRON_SERVICE', 0);
define('IS_SCRIPT_SERVICE', 1);
define('PHP_BIN_FILE','/usr/bin/php');
define('WORKER_SERVICE_NAME', makeServerName($_SERVER['argv'][2]));

$options = \Swoolefy\Core\SystemEnv::inputOptions();
$command =\Swoolefy\Core\SystemEnv::getOption('c');
$workPidFile = $command.'@'.substr(md5(json_encode($options)),0, 12);

define('WORKER_START_SCRIPT_FILE', str_contains($_SERVER['SCRIPT_FILENAME'], $_SERVER['PWD']) ? $_SERVER['SCRIPT_FILENAME'] : $_SERVER['PWD'].'/'.$_SERVER['SCRIPT_FILENAME']);
define('WORKER_PID_FILE_ROOT', '/tmp/workerfy/log/'.WORKER_SERVICE_NAME);
define('WORKER_PID_FILE', WORKER_PID_FILE_ROOT."/{$workPidFile}.pid");
define('WORKER_STATUS_FILE',WORKER_PID_FILE_ROOT.'/status.log');
define('WORKER_CTL_LOG_FILE',WORKER_PID_FILE_ROOT.'/ctl.log');
define('CLI_TO_WORKER_PIPE',WORKER_PID_FILE_ROOT.'/cli.pipe');
define('WORKER_TO_CLI_PIPE',WORKER_PID_FILE_ROOT.'/ctl.pipe');

date_default_timezone_set('Asia/Shanghai');

// script 为空即可
define('PROCESS_CLASS', []);

// 定义脚本文件夹的根目录
define('ROOT_NAMESPACE', [
    'Test' => '\\Test\\Scripts'
]);

include __DIR__.'/swoolefy';