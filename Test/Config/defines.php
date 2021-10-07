<?php
defined('APP_NAME') or define('APP_NAME', "Test");
defined('APP_PATH') or define('APP_PATH',dirname(__DIR__));
defined('ROOT_PATH') or define('ROOT_PATH',dirname(APP_PATH));

// 日志目录
define('LOG_PATH', APP_PATH.'/Log');

// 定义smarty(需要安装smarty)
define('SMARTY_TEMPLATE_PATH',APP_PATH.'/View/');
define('SMARTY_COMPILE_DIR',APP_PATH.'/Runtime/');
define('SMARTY_CACHE_DIR',APP_PATH.'/Runtime/');