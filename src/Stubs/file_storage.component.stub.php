<?php

declare(strict_types=1);

use Swoolefy\Library\FileStorageSystem\FileStorageManager;

/**
 * FileStorageSystem 组件注册模板（create 应用时复制到 Config/component/file_storage.php）。
 *
 * - 每协程/请求 new Manager（OK）；勿用进程级 static 单例缓存带 STS 可变状态的 Manager
 * - 用法：Application::getApp()->get('file_storage')->disk()
 *
 * @see docs/fileStorageSystem.md
 * @see Config/file_storage_system.php
 */
return [
    'file_storage' => static function (): FileStorageManager {
        $configFile = APP_PATH . '/Config/file_storage_system.php';
        $config = is_file($configFile) ? include $configFile : [];

        return new FileStorageManager(is_array($config) ? $config : []);
    },
];
