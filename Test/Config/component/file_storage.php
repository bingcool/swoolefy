<?php

declare(strict_types=1);

use Swoolefy\Library\FileStorageSystem\FileStorageManager;

/**
 * FileStorageSystem DI：Application::getApp()->get('file_storage')->disk()
 *
 * @see Config/file_storage_system.php
 */
return [
    'file_storage' => static function (): FileStorageManager {
        $configFile = APP_PATH . '/Config/file_storage_system.php';
        $config = is_file($configFile) ? include $configFile : [];

        return new FileStorageManager(is_array($config) ? $config : []);
    },
];
