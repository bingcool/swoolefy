<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Core;

use PHPUintTest\TestCase;
use Swoolefy\Core\SwoolefyException;

final class SwoolefyExceptionHandleErrorTest extends TestCase
{
    private int $reporting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reporting = error_reporting();
    }

    protected function tearDown(): void
    {
        error_reporting($this->reporting);
        parent::tearDown();
    }

    /**
     * @ 运算符生效时，PHP 在调用错误处理器前把 error_reporting() 降到不含 E_WARNING。
     */
    public function testSuppressedWarningIsNotConvertedToException(): void
    {
        error_reporting(E_ALL & ~E_WARNING);

        $this->assertFalse(SwoolefyException::handleError(E_WARNING, 'fopen(x): Failed to open stream', __FILE__, __LINE__));
    }

    public function testWarningWithoutSuppressionStillThrows(): void
    {
        error_reporting(E_ALL);

        $this->expectException(\ErrorException::class);
        SwoolefyException::handleError(E_WARNING, 'fopen(x): Failed to open stream', __FILE__, __LINE__);
    }
}
