<?php

declare(strict_types=1);

use Swoolefy\Core\Application;
use Swoolefy\Library\Image\ImageManager;

return [
    'image' => static function (): ImageManager {
        $configFile = APP_PATH . '/Config/image.php';
        $config = is_file($configFile) ? include $configFile : [];

        return new ImageManager(is_array($config) ? $config : [], null);
    },
];
