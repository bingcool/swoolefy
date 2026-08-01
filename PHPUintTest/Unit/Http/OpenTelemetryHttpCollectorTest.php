<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Http;

use PHPUintTest\TestCase;
use ReflectionProperty;
use Swoolefy\Http\OpenTelemetry\OpenTelemetryAttributeSanitizer;
use Swoolefy\Http\OpenTelemetry\OpenTelemetryConfig;
use Swoolefy\Http\OpenTelemetry\OpenTelemetryHttpCollector;
use Swoolefy\Http\Route;
use Swoolefy\Http\RouteOption;
use Swoolefy\Library\OpenTelemetry\SemConv\TraceAttributes;

/**
 * 阶段六 8.3：OpenTelemetry 默认最小采集。
 */
final class OpenTelemetryHttpCollectorTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->clearRouteMap();
        parent::tearDown();
    }

    public function testGlobalOffDoesNotCollect(): void
    {
        $option = (new RouteOption())->enableOpenTelemetry(true);
        $this->assertFalse(OpenTelemetryHttpCollector::shouldCollect(false, $option));
        $this->assertFalse(OpenTelemetryHttpCollector::shouldCollect(false, null));
    }

    public function testRouteFalseDoesNotCollectWhenGlobalOn(): void
    {
        $option = (new RouteOption())->enableOpenTelemetry(false);
        $this->assertFalse(OpenTelemetryHttpCollector::shouldCollect(true, $option));
    }

    public function testGlobalOnAndRouteDefaultCollects(): void
    {
        $this->assertTrue(OpenTelemetryHttpCollector::shouldCollect(true, new RouteOption()));
        $this->assertTrue(OpenTelemetryHttpCollector::shouldCollect(true, null));
        $this->assertTrue(OpenTelemetryHttpCollector::shouldCollect(
            true,
            (new RouteOption())->enableOpenTelemetry(true),
        ));
    }

    public function testFindRouteOptionRespectsEnableOpenTelemetryFalse(): void
    {
        $this->clearRouteMap();
        Route::post('/api/login', [
            'dispatch_route' => ['DummyController', 'login'],
        ])->enableOpenTelemetry(false);

        $option = Route::findRouteOption('/api/login', 'POST');
        $this->assertInstanceOf(RouteOption::class, $option);
        $this->assertFalse($option->isEnableOpenTelemetry());
        $this->assertFalse(OpenTelemetryHttpCollector::shouldCollect(true, $option));
    }

    public function testBodyCollectedByDefault(): void
    {
        $attrs = OpenTelemetryHttpCollector::buildAttributes(
            'POST',
            '/api/order',
            [],
            ['name' => 'alice', 'qty' => 1],
            [],
            OpenTelemetryConfig::fromArray([]),
            1,
            'host-1',
        );

        $this->assertArrayHasKey(TraceAttributes::HTTP_REQUEST_BODY, $attrs);
        $this->assertStringContainsString('alice', $attrs[TraceAttributes::HTTP_REQUEST_BODY]);
        $this->assertStringContainsString('"qty":1', $attrs[TraceAttributes::HTTP_REQUEST_BODY]);
    }

    public function testBodyCollectCanBeDisabled(): void
    {
        $attrs = OpenTelemetryHttpCollector::buildAttributes(
            'POST',
            '/api/order',
            [],
            ['name' => 'alice'],
            [],
            OpenTelemetryConfig::fromArray([
                'otel' => ['collect_request_body' => false],
            ]),
        );

        $this->assertArrayNotHasKey(TraceAttributes::HTTP_REQUEST_BODY, $attrs);
    }

    public function testSanitizeEnabledByDefault(): void
    {
        $attrs = OpenTelemetryHttpCollector::buildAttributes(
            'POST',
            '/api/login',
            [
                'authorization' => 'Bearer secret-token',
                'cookie' => 'sid=abc',
                'set-cookie' => 'sid=abc',
                'content-type' => 'application/json',
            ],
            [
                'username' => 'alice',
                'password' => 'plain-pwd',
                'access_token' => 'tok-1',
                'user_credential' => 'cred-secret-value',
            ],
            ['token' => 'q-tok'],
            OpenTelemetryConfig::fromArray([]),
        );

        $headersJson = $attrs[TraceAttributes::HTTP_REQUEST_HEADERS];
        $bodyJson = $attrs[TraceAttributes::HTTP_REQUEST_BODY];
        $query = $attrs[TraceAttributes::HTTP_REQUEST_QUERY_PARAMS];

        $this->assertStringNotContainsString('secret-token', $headersJson);
        $this->assertStringNotContainsString('sid=abc', $headersJson);
        $this->assertStringContainsString('[REDACTED]', $headersJson);
        $this->assertStringContainsString('application', $headersJson);
        $this->assertStringContainsString('json', $headersJson);

        $this->assertStringNotContainsString('plain-pwd', $bodyJson);
        $this->assertStringNotContainsString('tok-1', $bodyJson);
        $this->assertStringNotContainsString('cred-secret-value', $bodyJson);
        $this->assertStringContainsString('alice', $bodyJson);
        $this->assertStringContainsString('[REDACTED]', $bodyJson);

        $this->assertStringNotContainsString('q-tok', $query);
        $this->assertStringContainsString('[REDACTED]', $query);
    }

    public function testSanitizeCanBeDisabled(): void
    {
        $attrs = OpenTelemetryHttpCollector::buildAttributes(
            'POST',
            '/api/login',
            ['authorization' => 'Bearer keep-me'],
            ['password' => 'keep-pwd'],
            [],
            OpenTelemetryConfig::fromArray([
                'otel' => ['sanitize_enabled' => false],
            ]),
        );

        $this->assertStringContainsString('keep-me', $attrs[TraceAttributes::HTTP_REQUEST_HEADERS]);
        $this->assertStringContainsString('keep-pwd', $attrs[TraceAttributes::HTTP_REQUEST_BODY]);
        $this->assertStringNotContainsString('[REDACTED]', $attrs[TraceAttributes::HTTP_REQUEST_HEADERS]);
    }

    public function testAttributeMaxLengthTruncatesAndMarks(): void
    {
        $longBody = ['note' => str_repeat('x', 200)];
        $attrs = OpenTelemetryHttpCollector::buildAttributes(
            'POST',
            '/api/x',
            [],
            $longBody,
            [],
            OpenTelemetryConfig::fromArray([
                'otel' => [
                    'sanitize_enabled' => false,
                    'attribute_max_length' => 40,
                ],
            ]),
        );

        $bodyAttr = $attrs[TraceAttributes::HTTP_REQUEST_BODY];
        $this->assertLessThanOrEqual(40, strlen($bodyAttr));
        $this->assertStringEndsWith('...[TRUNCATED]', $bodyAttr);
    }

    public function testUnsetMaxLengthDoesNotTruncate(): void
    {
        $payload = str_repeat('y', 5000);
        $attrs = OpenTelemetryHttpCollector::buildAttributes(
            'POST',
            '/api/x',
            [],
            ['blob' => $payload],
            [],
            OpenTelemetryConfig::fromArray([
                'otel' => [
                    'sanitize_enabled' => false,
                    'attribute_max_length' => null,
                ],
            ]),
        );

        $bodyAttr = $attrs[TraceAttributes::HTTP_REQUEST_BODY];
        $this->assertStringContainsString($payload, $bodyAttr);
        $this->assertStringNotContainsString('...[TRUNCATED]', $bodyAttr);
    }

    public function testConfigDefaults(): void
    {
        $config = OpenTelemetryConfig::fromArray([]);
        $this->assertTrue($config->isSanitizeEnabled());
        $this->assertNull($config->attributeMaxLength());
        $this->assertTrue($config->collectRequestBody());
    }

    public function testTruncateHelper(): void
    {
        $this->assertSame('abc', OpenTelemetryAttributeSanitizer::truncate('abc', null));
        $this->assertSame('abc', OpenTelemetryAttributeSanitizer::truncate('abc', 0));
        // max=15，后缀 ...[TRUNCATED] 占 14，仅保留 1 字符正文
        $this->assertSame('x...[TRUNCATED]', OpenTelemetryAttributeSanitizer::truncate(str_repeat('x', 20), 15));
    }

    private function clearRouteMap(): void
    {
        $prop = new ReflectionProperty(Route::class, 'routeMap');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }
}
