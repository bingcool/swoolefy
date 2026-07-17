<?php

/**
 * Auth 组件注册。
 *
 * ## auth.guard
 * - 进程内惰性创建 {@see \Swoolefy\Support\Auth\JwtAuthGuard}（读 Config/auth.php）
 * - 供 AuthenticateMiddleware / WebsocketAuthCallback 等 `Application::getApp()->get('auth.guard')`
 * - **可复用**：密钥与解析器配置（无请求态）
 * - **禁止**：在 Guard 实例上缓存「当前用户」——身份只进协程 FrameworkContext
 *
 * 本文件由 SystemEnv::loadComponents() 与其它 component/*.php 合并加载。
 *
 * @see docs/Auth.md
 */

use Swoolefy\Support\Auth\JwtAuthGuard;

$authcConfig = include APP_PATH . '/Config/auth.php';
return [
    'auth.guard' => static function () use($authcConfig) {
        return new JwtAuthGuard($authcConfig['jwt'] ?? []);
    },
];
