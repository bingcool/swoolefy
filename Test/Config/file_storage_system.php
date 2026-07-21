<?php

declare(strict_types=1);

/**
 * Test 应用 FileStorageSystem 配置（默认 local，供 FileStorageController / Health 探针使用）。
 *
 * @see \Swoolefy\Library\FileStorageSystem\FileStorageManager
 * @see Config/component/file_storage.php
 */

return [
    'default_provider' => env('FILE_STORAGE_DEFAULT', 'local'),
    'file_system_providers' => [
        'local' => [
            'driver' => 'local',
            'root' => (defined('APP_PATH') ? APP_PATH : __DIR__ . '/..') . '/Storage/FileStorage',
            'public_base_url' => env('FILE_STORAGE_LOCAL_PUBLIC_URL', ''),
            'throw' => true,
        ],
        'fake' => [
            'driver' => 'fake',
        ],
    ],
];
