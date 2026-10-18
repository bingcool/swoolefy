<?php

declare(strict_types=1);

/**
 * Qrcode 组件配置（library Swoolefy\Library\Qrcode）。
 */
return [
    'qrcode' => [
        'default' => [
            'size' => 280,
            'margin' => 1,
            'level' => 'M',
            'format' => 'png',
            'fill' => ['#ffffff', '#000000'],
        ],
        'logo' => [
            'enabled' => false,
            'path' => APP_PATH . '/Resource/Image/watermark.png',
            'ratio' => 0.18,
        ],
        'strict_length' => true,
    ],
];
