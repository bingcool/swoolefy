<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Util;

use PHPUintTest\TestCase;
use Swoolefy\Util\Helper;

/**
 * Helper IP / 精度相关回归（Optimize20260728 P2 3.1～3.3）。
 */
final class HelperIpAndRoundTest extends TestCase
{
    public function testIsValidateIpAcceptsIpv4AndIpv6(): void
    {
        $this->assertTrue(Helper::isValidateIp('127.0.0.1'));
        $this->assertTrue(Helper::isValidateIp('8.8.8.8'));
        $this->assertTrue(Helper::isValidateIp('::1'));
        $this->assertTrue(Helper::isValidateIp('2001:db8::1'));
        $this->assertFalse(Helper::isValidateIp('not-an-ip'));
        $this->assertFalse(Helper::isValidateIp('999.1.1.1'));
    }

    public function testIsValidIpv4RejectsIpv6(): void
    {
        $this->assertTrue(Helper::isValidIpv4('192.168.1.1'));
        $this->assertFalse(Helper::isValidIpv4('::1'));
    }

    public function testPublicAndPrivateIp(): void
    {
        $this->assertTrue(Helper::isPublicIp('8.8.8.8'));
        $this->assertFalse(Helper::isPublicIp('10.0.0.1'));
        $this->assertFalse(Helper::isPublicIp('192.168.0.1'));
        $this->assertFalse(Helper::isPublicIp('127.0.0.1'));

        $this->assertTrue(Helper::isPrivateIp('10.1.2.3'));
        $this->assertTrue(Helper::isPrivateIp('192.168.1.1'));
        $this->assertFalse(Helper::isPrivateIp('8.8.8.8'));
        $this->assertFalse(Helper::isPrivateIp('not-ip'));

        // 废弃别名：按名称语义指向私网
        $this->assertTrue(Helper::isInternalIp('10.0.0.1'));
        $this->assertFalse(Helper::isInternalIp('1.1.1.1'));
    }

    public function testRoundByPrecision(): void
    {
        $this->assertSame(1.24, Helper::roundByPrecision(1.235, 2));
        $this->assertSame(-1.3, Helper::roundByPrecision(-1.25, 1));
        $this->assertSame(2.0, Helper::roundByPrecision(1.6, 0));
        $this->assertSame(1.2346, Helper::roundByPrecision(1.23456, 4));
        $this->assertSame(10.0, Helper::roundByPrecision(10.0, 0));
    }

    public function testRandStringLengthAndCharset(): void
    {
        $s = Helper::randString(16);
        $this->assertSame(16, strlen($s));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]+$/', $s);

        $digits = Helper::randString(8, true);
        $this->assertSame(8, strlen($digits));
        $this->assertMatchesRegularExpression('/^[0-9]+$/', $digits);

        $this->assertSame('', Helper::randString(0));
    }
}
