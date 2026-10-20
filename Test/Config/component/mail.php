<?php

declare(strict_types=1);

use Swoolefy\Library\Mail\MailerManager;

return [
    'mail' => static function (): MailerManager {
        $configFile = APP_PATH . '/Config/mail.php';
        $config = is_file($configFile) ? include $configFile : [];

        // 传 null logger：MailerManager 的发送结果日志在业务侧按需注入 PSR-3
        return new MailerManager(is_array($config) ? $config : [], null);
    },
];
