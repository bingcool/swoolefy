<?php

declare(strict_types=1);

use Swoolefy\Support\DocumentOcr\DocumentOcrFactory;

/**
 * DocumentOcr 组件注册（文件名 document_parser.php）。
 *
 * 说明：
 * - 文件名是 document_parser.php，DI 注册名是 document_ocr（二者刻意不同）
 * - 读取 APP_PATH/Config/document_ocr.php；文件不存在时用空配置（两 Driver 均按默认启用）
 * - 用法：Application::getApp()->get('document_ocr')->parseFile($path)
 *
 * 如需注入外部 Guzzle Client（例如统一 CurlProxyHandler），可改为：
 *
 *   return DocumentOcrFactory::fromConfig($config, deepSeekClient: $client);
 *
 * @see docs/DocumentOcr.md
 * @see Config/document_ocr.php
 */
return [
    // DI 名：document_ocr → DocumentOcrFactory
    'document_ocr' => static function (): DocumentOcrFactory {
        $configFile = APP_PATH . '/Config/document_ocr.php';
        $config = is_file($configFile) ? include $configFile : [];

        return DocumentOcrFactory::fromConfig(is_array($config) ? $config : []);
    },
];
