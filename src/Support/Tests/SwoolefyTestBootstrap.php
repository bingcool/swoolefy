<?php

declare(strict_types=1);

/**
 * Support 单测在 Swoole 协程内走 goApp/EventApp 时的最小框架常量与 helper stub。
 *
 * 生产 Worker 由入口文件定义；CLI 单测需预先 bootstrap，否则 EventApp 加载 conf 会失败。
 */
$testAppRoot = dirname(__DIR__, 3) . '/Test';

if (!\defined('START_DIR_ROOT')) {
    \define('START_DIR_ROOT', $testAppRoot);
}
if (!\defined('APP_PATH')) {
    \define('APP_PATH', $testAppRoot);
}
if (!\defined('APP_NAME')) {
    \define('APP_NAME', 'swoolefy_test');
}
if (!\defined('LOG_PATH')) {
    \define('LOG_PATH', APP_PATH . '/Storage/Logs');
}

if (!\function_exists('isDaemonService')) {
    function isDaemonService(): bool
    {
        return false;
    }
}
if (!\function_exists('isScriptService')) {
    function isScriptService(): bool
    {
        return false;
    }
}
if (!\function_exists('isCronService')) {
    function isCronService(): bool
    {
        return false;
    }
}
if (!\function_exists('isWorkerService')) {
    function isWorkerService(): bool
    {
        return isDaemonService() || isScriptService() || isCronService();
    }
}
if (!\function_exists('isCliService')) {
    function isCliService(): bool
    {
        return !isWorkerService();
    }
}
if (!\function_exists('isDaemon')) {
    function isDaemon(): bool
    {
        return false;
    }
}
