<?php

declare(strict_types=1);

use Swoolefy\Core\Application;
use Swoolefy\Core\Dto\ContainerObjectDto;
use Swoolefy\Library\Image\ImageManager;
use Swoolefy\Library\Qrcode\QrcodeManager;

return [
    'qrcode' => static function (): QrcodeManager {
        $configFile = APP_PATH . '/Config/qrcode.php';
        $config = is_file($configFile) ? include $configFile : [];
        $app = Application::getApp();
        $image = null;
        try {
            $image = $app->get('image');
            if ($image instanceof ContainerObjectDto) {
                $image = $image->getObject();
            }
        } catch (\Throwable) {
            // Logo 测试需 image 组件；未注册时仅 withLogo 会失败
        }

        return new QrcodeManager(
            is_array($config) ? $config : [],
            null,
            $image instanceof ImageManager ? $image : null,
        );
    },
];
