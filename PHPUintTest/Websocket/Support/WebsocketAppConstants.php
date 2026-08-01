<?php

declare(strict_types=1);

namespace PHPUintTest\Websocket\Support;

/**
 * WebsocketService 相关单测所需的 APP_* 常量（幂等 define）。
 */
final class WebsocketAppConstants
{
    public static function ensure(): void
    {
        $root = dirname(__DIR__, 3);
        if (!defined('APP_NAME')) {
            define('APP_NAME', 'WebsocketService');
        }
        if (!defined('APP_PATH')) {
            define('APP_PATH', $root . '/WebsocketService');
        }
        if (!defined('WORKER_PORT')) {
            define('WORKER_PORT', 9508);
        }
    }
}
