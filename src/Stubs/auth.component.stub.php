<?php

/**
 * Auth 组件注册模板（create 时复制到 Config/component/auth.php）
 *
 * ## auth.guard
 * - 惰性创建 {@see \Swoolefy\Support\Auth\JwtAuthGuard}（读 Config/auth.php）
 * - 供 AuthenticateMiddleware / WebsocketAuthCallback：`Application::getApp()->get('auth.guard')`
 * - **可复用**：密钥与解析器配置（无请求态）
 * - **禁止**：在 Guard 上缓存「当前用户」——身份只进协程 FrameworkContext
 *
 * @see docs/Auth.md
 * @see Config/auth.php
 */

use Swoolefy\Support\Auth\JwtAuthGuard;

$authConfig = include APP_PATH . '/Config/auth.php';

return [
    'auth.guard' => static function () use ($authConfig) {
        return new JwtAuthGuard($authConfig['jwt'] ?? []);
    },
];
