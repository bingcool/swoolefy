<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap（phpunit.xml.dist）。
 *
 * composer autoload-dev 已通过 files 注册 Test\ / PhpUintTest\；
 * 此处再 require vendor，保证与 CLI `vendor/bin/phpunit`、PhpStorm 行为一致。
 */
require dirname(__DIR__) . '/vendor/autoload.php';
