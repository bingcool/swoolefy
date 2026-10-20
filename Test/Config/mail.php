<?php

declare(strict_types=1);

/**
 * Mail 组件配置（library Swoolefy\Library\Mail）。
 *
 * Test 环境只启用 log transport：仍然执行完整的消息编译、模板渲染、
 * 附件读取与收件人校验，但不创建 PHPMailer、不连接 SMTP。
 * 接真实邮箱时新增 smtp mailer，把 default_mailer 指过去，账号密码从 env 读取。
 */
return [
    'mail' => [
        'default_mailer' => 'log',

        'mailers' => [
            'log' => [
                'transport' => 'log',
                'from' => ['address' => 'log@example.com', 'name' => 'Swoolefy Mail Test'],
            ],
        ],

        'template' => [
            'view_path' => APP_PATH . '/Resource/Mail',
            'vars' => [
                'app_name' => 'Swoolefy Mail Test',
            ],
        ],

        'attachments' => [
            'max_bytes' => 1024 * 1024,
        ],

        // 联调故意设置得较小，/api/mail/invalid?case=too_many 用 6 个收件人触发
        'max_recipients' => 5,
    ],
];
