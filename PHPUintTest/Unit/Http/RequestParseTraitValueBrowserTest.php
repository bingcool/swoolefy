<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Http;

use PHPUintTest\Support\HttpRequestHarness;
use PHPUintTest\TestCase;

/**
 * RequestParseTrait getValue / getBrowser（Optimize20260728 P2 3.4～3.5）。
 */
final class RequestParseTraitValueBrowserTest extends TestCase
{
    public function testGetValueDefaultAndRequired(): void
    {
        $input = HttpRequestHarness::requestInput();
        $this->assertNull($input->getValue('missing'));
        $this->assertSame('fallback', $input->getValue('missing', 'fallback'));

        $input->setValue('limit', 10);
        $this->assertSame(10, $input->getValue('limit'));
        $this->assertSame(10, $input->getRequiredValue('limit'));

        $this->expectException(\InvalidArgumentException::class);
        $input->getRequiredValue('not-set');
    }

    public function testGetBrowserSafeWhenUaMissingOrAtOffsetZero(): void
    {
        $empty = HttpRequestHarness::requestInput();
        $this->assertSame('Unkown()', $empty->getBrowser());

        // UA 以 Firefox/ 开头时 stripos === 0，旧实现会误判
        $ff = HttpRequestHarness::requestInput('GET', '/', [], [], ['user-agent' => 'Firefox/120.0']);
        $this->assertSame('Firefox(120.0)', $ff->getBrowser());

        $chrome = HttpRequestHarness::requestInput(
            'GET',
            '/',
            [],
            [],
            ['user-agent' => 'Mozilla/5.0 Chrome/120.0.0.0 Safari/537.36']
        );
        $this->assertSame('Chrome(120.0.0.0)', $chrome->getBrowser());
    }
}
