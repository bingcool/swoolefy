<?php

declare(strict_types=1);

use Swoolefy\Library\Mail\MailerManager;

$configFile = APP_PATH . '/Config/mail.php';
$config = is_file($configFile) ? include_once $configFile : [];

return [
    'mail' => static function () use($config): MailerManager {
        // 传 null logger：MailerManager 的发送结果日志在业务侧按需注入 PSR-3
        return new MailerManager(is_array($config) ? $config : [], null);
    },
];
