<?php

declare(strict_types=1);

/**
 * Image 组件配置（library Swoolefy\Library\Image）。
 */
return [
    'image' => [
        'driver' => env('IMAGE_DRIVER', 'gd'),
        'max_pixels' => 40_000_000,
        'encode' => [
            'format' => 'jpeg',
            'quality' => 82,
            'progressive' => true,
        ],
        'watermark' => [
            'image' => [
                'path' => APP_PATH . '/Resource/Image/watermark.png',
                'position' => 'bottom-right',
                'offset_x' => 12,
                'offset_y' => 12,
                'opacity' => 0.55,
            ],
            'text' => [
                'content' => 'swoolefy-test',
                'font' => APP_PATH . '/Resource/Image/DejaVuSans.ttf',
                'size' => 22,
                'color' => '#ffffff',
                'opacity' => 0.45,
                'position' => 'bottom-right',
                'offset_x' => 12,
                'offset_y' => 40,
            ],
        ],
        'presets' => [
            'thumb' => ['mode' => 'cover', 'size' => [200, 200]],
            'list' => ['mode' => 'contain', 'size' => [750, 0]],
            'detail' => ['mode' => 'contain', 'size' => [1200, 1200]],
            'avatar' => [
                'mode' => 'cover',
                'size' => [128, 128],
                'encode' => ['format' => 'webp'],
            ],
        ],
    ],
];
