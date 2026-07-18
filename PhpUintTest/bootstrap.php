<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap：
 * 1) composer：仅 Swoolefy + 第三方（PHPUnit / Guzzle …）
 * 2) Test/autoloader.php：注册 Test\（不灌入 APP_* 业务常量，避免污染纯 Unit）
 * 3) PhpUintTest/Autoloader.php：注册 PhpUintTest\
 *
 * Test\ / PhpUintTest\ 不进 composer.json autoload。
 */
define('SWOOLEFY_PHPUNIT_BOOTSTRAP', true);

require dirname(__DIR__) . '/vendor/autoload.php';

// 与 cli.php 一致：START_DIR_ROOT=仓库根（Test\autoloader 拼路径用）
$projectRoot = dirname(__DIR__);
defined('START_DIR_ROOT') or define('START_DIR_ROOT', $projectRoot);

require $projectRoot . '/Test/autoloader.php';
require __DIR__ . '/Autoloader.php';
