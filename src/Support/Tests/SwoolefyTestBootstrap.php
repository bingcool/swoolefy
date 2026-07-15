<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

declare(strict_types=1);

/**
 * Support 单测在 Swoole 协程内走 goApp/EventApp 时的最小框架常量与 helper stub。
 *
 * ## 为何需要
 * 生产 Worker 由应用入口（如 `Test/index.php` / `start.php`）定义：
 * `START_DIR_ROOT`、`APP_PATH`、`APP_NAME`、`LOG_PATH` 以及 `isDaemonService()` 等。
 * CLI 直接 `php ...Test.php` 时没有这些定义；一旦代码路径加载 EventApp / conf，
 * 会因常量缺失或 helper 未定义而失败。
 *
 * ## 本文件做什么
 * | 项 | 行为 |
 * |----|------|
 * | 路径常量 | 指向仓库内 `Test/` 应用根，便于读到 demo conf |
 * | 进程类型 helper | 一律按「非 Worker CLI」返回，避免误走守护进程分支 |
 *
 * ## 谁会 require
 * - {@see PhaseCProductionTest.php}（协程 / GoWaitGroup）
 * - {@see SupportLogIntegrationTest.php}（Util\Log 写盘探测）
 *
 * 幂等：常量与函数均用 `defined` / `function_exists` 保护，可重复 require。
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
    /** CLI 单测：非守护进程服务 */
    function isDaemonService(): bool
    {
        return false;
    }
}
if (!\function_exists('isScriptService')) {
    /** CLI 单测：非脚本服务 */
    function isScriptService(): bool
    {
        return false;
    }
}
if (!\function_exists('isCronService')) {
    /** CLI 单测：非 Cron 服务 */
    function isCronService(): bool
    {
        return false;
    }
}
if (!\function_exists('isWorkerService')) {
    /** Worker = daemon / script / cron 任一；单测三者皆 false */
    function isWorkerService(): bool
    {
        return isDaemonService() || isScriptService() || isCronService();
    }
}
if (!\function_exists('isCliService')) {
    /** 与 isWorkerService 互斥：单测视为 CLI */
    function isCliService(): bool
    {
        return !isWorkerService();
    }
}
if (!\function_exists('isDaemon')) {
    /** 进程未以 daemon 方式启动 */
    function isDaemon(): bool
    {
        return false;
    }
}
