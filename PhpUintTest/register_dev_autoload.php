<?php

declare(strict_types=1);

/**
 * composer autoload-dev files 入口：PhpStorm / vendor/autoload 单独跑测试时也能解析
 * Test\ 与 PhpUintTest\（二者不进 composer psr-4）。
 *
 * 不定义 APP_PATH，故不会加载 Test 业务 constants（避免污染 Unit）。
 */
$projectRoot = dirname(__DIR__);
defined('START_DIR_ROOT') or define('START_DIR_ROOT', $projectRoot);

require_once $projectRoot . '/Test/Autoloader.php';
require_once __DIR__ . '/Autoloader.php';
