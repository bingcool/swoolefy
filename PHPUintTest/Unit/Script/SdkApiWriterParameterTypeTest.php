<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Script;

use PHPUintTest\TestCase;
use ReflectionMethod;
use Swoolefy\Script\Sdk\SdkApiWriter;
use Test\Module\Cron\Controller\CronAdminController;

/**
 * SDK Client 方法签名：框架类参数须写根命名空间，避免在 Client 子命名空间下被误解析。
 */
final class SdkApiWriterParameterTypeTest extends TestCase
{
    public function testScalarParametersPrefixFrameworkClassWithLeadingBackslash(): void
    {
        $writer = new SdkApiWriter('/tmp', 'GenerateSdk\\Swoolefy\\Test', 'Test\\');
        $getScalar = new ReflectionMethod(SdkApiWriter::class, 'getScalarParameters');
        $getScalar->setAccessible(true);

        $params = $getScalar->invoke(
            $writer,
            new ReflectionMethod(CronAdminController::class, 'index'),
        );

        $this->assertCount(1, $params);
        $this->assertSame('\\Swoolefy\\Http\\ResponseOutput $response', $params[0]);
    }

    public function testScalarParametersKeepBuiltinTypesUnchanged(): void
    {
        $writer = new SdkApiWriter('/tmp', 'GenerateSdk\\Swoolefy\\Test', 'Test\\');
        $format = new ReflectionMethod(SdkApiWriter::class, 'formatParameterTypeName');
        $format->setAccessible(true);

        $this->assertSame('string', $format->invoke($writer, 'string'));
        $this->assertSame('array', $format->invoke($writer, 'array'));
        $this->assertSame('\\Swoolefy\\Http\\ResponseOutput', $format->invoke($writer, 'Swoolefy\\Http\\ResponseOutput'));
        $this->assertSame('\\Swoolefy\\Http\\ResponseOutput', $format->invoke($writer, '\\Swoolefy\\Http\\ResponseOutput'));
    }
}
