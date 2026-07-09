<?php

declare(strict_types=1);

use Swoolefy\Support\DocumentOcr\DocumentOcrFactory;

/**
 * DocumentOcr 组件注册（文件名 document_parser.php）。
 *
 * 用法：Application::getApp()->get('document_ocr')->parseFile($path)
 */
return [
    'document_ocr' => static function (): DocumentOcrFactory {
        $configFile = APP_PATH . '/Config/document_ocr.php';
        $config = is_file($configFile) ? include $configFile : [];

        return DocumentOcrFactory::fromConfig(is_array($config) ? $config : []);
    },
];
