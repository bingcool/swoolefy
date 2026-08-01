<?php

declare(strict_types=1);

/**
 * CreateCmd 单测用全局 CLI helper stub（加载 Protocol/conf.php 时需要）。
 */
if (!function_exists('isDaemonService')) {
    function isDaemonService(): bool
    {
        return false;
    }
}
if (!function_exists('isScriptService')) {
    function isScriptService(): bool
    {
        return false;
    }
}
if (!function_exists('isCronService')) {
    function isCronService(): bool
    {
        return false;
    }
}
if (!function_exists('isWorkerService')) {
    function isWorkerService(): bool
    {
        return isDaemonService() || isScriptService() || isCronService();
    }
}
