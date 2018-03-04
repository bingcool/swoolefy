<?php
define('APP_NAME','App');
define('APP_PATH',dirname(__DIR__));
define('ROOT_PATH',dirname(APP_PATH));

// 日志目录
define('LOG_PATH',APP_PATH.'/Log');

// 定义smarty
define('SMARTY_TEMPLATE_PATH',APP_PATH.'/View/');
define('SMARTY_COMPILE_DIR',APP_PATH.'/Runtime/');
define('SMARTY_CACHE_DIR',APP_PATH.'/Runtime/');
