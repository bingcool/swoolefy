<?php

declare(strict_types=1);

namespace PHPUintTest;

use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Unit 基类：无协程调度、无网络。统一使用 PHPUnit 断言。
 */
abstract class TestCase extends BaseTestCase
{
}
