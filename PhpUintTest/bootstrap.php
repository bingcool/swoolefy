<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap：autoload + 可选 Support 协程 stub（由 CoroutineTestCase 再 require 一次亦幂等）。
 */

require dirname(__DIR__) . '/vendor/autoload.php';
