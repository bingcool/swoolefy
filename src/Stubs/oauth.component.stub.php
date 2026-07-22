<?php

declare(strict_types=1);

use Swoolefy\Library\Oauth\OauthManager;

/**
 * Oauth 组件注册模板（create 应用时复制到 Config/component/oauth.php）。
 *
 * - 每协程/请求通过 DI get 取 Manager；勿用进程级 static 缓存带 accessToken 的 Client
 * - 用法：Application::getApp()->get('oauth')->provider('qq')
 *
 * @see docs/Oauth.md
 * @see Config/oauth.php
 */

$configFile = APP_PATH . '/Config/oauth.php';
$config = is_file($configFile) ? include $configFile : [];

return [
    'oauth' => static function() use($config) : OauthManager {
        return new OauthManager(is_array($config) ? $config : []);
    },
];
