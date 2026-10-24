<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Support\HeaderPropagation;

use PHPUintTest\TestCase;
use Swoolefy\Support\HeaderPropagation\HeaderPropagator;

/**
 * P1-06：只有可信代理来源才采信身份传播头。
 */
final class HeaderPropagatorTest extends TestCase
{
    public function testUntrustedSourceDropsForwardedIdentityAndUsesRemoteAddress(): void
    {
        $captured = HeaderPropagator::captureIncoming(
            [
                'X-User-Id' => 'forged-user',
                'x-user-code' => 'forged-code',
                'X-Tenant-Id' => 'forged-tenant',
                'X-User-Name' => 'forged-name',
                'X-Client-Ip' => '10.9.8.7',
                'X-User-Agent' => 'client-agent',
            ],
            'trace-1',
            [],
            '203.0.113.10',
        );

        $this->assertArrayNotHasKey(HeaderPropagator::HEADER_USER_ID, $captured);
        $this->assertArrayNotHasKey(HeaderPropagator::HEADER_USER_CODE, $captured);
        $this->assertArrayNotHasKey(HeaderPropagator::HEADER_TENANT_ID, $captured);
        $this->assertArrayNotHasKey(HeaderPropagator::HEADER_USER_NAME, $captured);
        $this->assertSame('203.0.113.10', $captured[HeaderPropagator::HEADER_CLIENT_IP]);
        $this->assertSame('client-agent', $captured[HeaderPropagator::HEADER_USER_AGENT]);
        $this->assertSame('trace-1', $captured[HeaderPropagator::HEADER_TRACE_ID]);
    }

    public function testLoopbackIsUntrustedWhenTrustedProxiesIsEmpty(): void
    {
        $captured = HeaderPropagator::captureIncoming(
            ['x-user-id' => 'forged-user', 'x-tenant-id' => 'forged-tenant'],
            'trace-2',
            [],
            '127.0.0.1',
        );

        $this->assertArrayNotHasKey(HeaderPropagator::HEADER_USER_ID, $captured);
        $this->assertArrayNotHasKey(HeaderPropagator::HEADER_TENANT_ID, $captured);
        $this->assertSame('127.0.0.1', $captured[HeaderPropagator::HEADER_CLIENT_IP]);
    }

    public function testTrustedProxyKeepsForwardedIdentity(): void
    {
        $captured = HeaderPropagator::captureIncoming(
            [
                'x-user-id' => 'gateway-user',
                'x-user-code' => 'gateway-code',
                'x-tenant-id' => 'gateway-tenant',
                'x-user-name' => 'gateway-name',
                'x-client-ip' => '198.51.100.20',
            ],
            'trace-3',
            ['10.0.0.0/8'],
            '10.1.2.3',
        );

        $this->assertSame('gateway-user', $captured[HeaderPropagator::HEADER_USER_ID]);
        $this->assertSame('gateway-code', $captured[HeaderPropagator::HEADER_USER_CODE]);
        $this->assertSame('gateway-tenant', $captured[HeaderPropagator::HEADER_TENANT_ID]);
        $this->assertSame('gateway-name', $captured[HeaderPropagator::HEADER_USER_NAME]);
        $this->assertSame('198.51.100.20', $captured[HeaderPropagator::HEADER_CLIENT_IP]);
    }

    public function testTrustedProxyWithoutClientIpDoesNotUseProxyAddress(): void
    {
        $captured = HeaderPropagator::captureIncoming(
            ['x-user-id' => 'gateway-user'],
            'trace-4',
            ['10.1.2.3'],
            '10.1.2.3',
        );

        $this->assertSame('gateway-user', $captured[HeaderPropagator::HEADER_USER_ID]);
        $this->assertArrayNotHasKey(HeaderPropagator::HEADER_CLIENT_IP, $captured);
    }

    public function testCidrMismatchIsUntrusted(): void
    {
        $captured = HeaderPropagator::captureIncoming(
            ['x-tenant-id' => 'forged-tenant'],
            'trace-5',
            ['10.0.0.0/8'],
            '192.168.1.10',
        );

        $this->assertArrayNotHasKey(HeaderPropagator::HEADER_TENANT_ID, $captured);
        $this->assertSame('192.168.1.10', $captured[HeaderPropagator::HEADER_CLIENT_IP]);
    }

    public function testExplicitTraceIdOverridesIncomingHeader(): void
    {
        $captured = HeaderPropagator::captureIncoming(
            ['x-trace-id' => 'client-trace'],
            'server-trace',
            [],
            '203.0.113.10',
        );

        $this->assertSame('server-trace', $captured[HeaderPropagator::HEADER_TRACE_ID]);
    }

    public function testEmptyHeaderValuesAreSkipped(): void
    {
        $captured = HeaderPropagator::captureIncoming(
            ['x-user-id' => '', 'x-tenant-id' => '   '],
            'trace-6',
            ['10.0.0.0/8'],
            '10.1.2.3',
        );

        $this->assertArrayNotHasKey(HeaderPropagator::HEADER_USER_ID, $captured);
        $this->assertArrayNotHasKey(HeaderPropagator::HEADER_TENANT_ID, $captured);
    }
}
