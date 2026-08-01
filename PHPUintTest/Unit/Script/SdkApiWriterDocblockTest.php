<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Script;

use PHPUintTest\TestCase;
use ReflectionMethod;
use Swoolefy\Script\Sdk\SdkApiWriter;
use Test\Controller\IndexController;

/**
 * SdkApiWriter 文档注释拷贝：UTF-8 中文不可被错误按字节拆行。
 *
 * 回归：preg_split('/\R/') 在非 /u 模式下会把 UTF-8 续字节 0x85
 *（出现在「入」「内」等汉字中）当成 NEL 换行，导致 SDK 注释乱码。
 */
final class SdkApiWriterDocblockTest extends TestCase
{
    public function testFormatControllerActionDocblockKeepsUtf8ChineseIntact(): void
    {
        $writer = new SdkApiWriter('/tmp', 'GenerateSdk\\Swoolefy\\Test', 'Test\\');
        $format = new ReflectionMethod(SdkApiWriter::class, 'formatControllerActionDocblock');
        $format->setAccessible(true);

        $doc = $format->invoke(
            $writer,
            new ReflectionMethod(IndexController::class, 'testLog1')
        );

        $this->assertNotFalse(@preg_match('//u', $doc), 'generated docblock must be valid UTF-8');
        $this->assertStringContainsString('测试 RunLog 业务日志写入（与 testLog 覆盖不同日志通道）。', $doc);
        $this->assertStringNotContainsString("\xEF\xBF\xBD", $doc);
        // Must remain a single summary line (no false split on 入)
        $this->assertDoesNotMatchRegularExpression(
            '/业务日志写.\n\s+\*.*与 testLog/u',
            $doc
        );
    }

    public function testFormatControllerActionDocblockKeepsCharsWith0x85Byte(): void
    {
        $writer = new SdkApiWriter('/tmp', 'GenerateSdk\\Swoolefy\\Test', 'Test\\');
        $format = new ReflectionMethod(SdkApiWriter::class, 'formatControllerActionDocblock');
        $format->setAccessible(true);

        $doc = $format->invoke(
            $writer,
            new ReflectionMethod(IndexController::class, 'testAddUser')
        );

        $this->assertNotFalse(@preg_match('//u', $doc), 'generated docblock must be valid UTF-8');
        $this->assertStringContainsString('测试插入用户表（主流程 + 协程内各插一条）。', $doc);
    }
}
