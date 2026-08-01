<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Http;

use PHPUintTest\TestCase;
use Swoolefy\Http\RateLimit\RateLimitConfig;

/**
 * HTTP RateLimit 配置解析。
 */
final class RateLimitConfigTest extends TestCase
{
    /**
     * 验证：默认值与 section 包装解析。
     */
    public function testDefaultsFromEmptyConfig(): void
    {
        $config = RateLimitConfig::fromArray([]);

        $this->assertTrue($config->enabled());
        $this->assertSame('redis', $config->redisComponent());
        $this->assertSame('http:', $config->keyPrefix());
        $this->assertSame(60, $config->defaultLimitNum());
        $this->assertSame(60, $config->defaultWindowSeconds());
        $this->assertSame(429, $config->httpStatus());
        $this->assertTrue($config->addHeaders());
        $this->assertTrue($config->useClientIpWhenAnonymous());
        $this->assertFalse($config->skipWhenUnauthenticated());
    }

    /**
     * 验证：routes 最长前缀匹配覆盖 limit/window。
     */
    public function testResolveRouteLimitsPrefersLongestPrefix(): void
    {
        $config = RateLimitConfig::fromArray([
            'rate_limit' => [
                'routes' => [
                    '/api/*' => ['limit_num' => 10, 'window_seconds' => 10],
                    '/api/v1/workflow/*' => ['limit' => 30, 'window' => 60],
                    '/api/rate-test1' => ['limit_num' => 5, 'window_seconds' => 5],
                ],
            ],
        ]);

        $this->assertSame(
            ['limit_num' => 5, 'window_seconds' => 5],
            $config->resolveRouteLimits('/api/rate-test1'),
        );
        $this->assertSame(
            ['limit_num' => 30, 'window_seconds' => 60],
            $config->resolveRouteLimits('/api/v1/workflow/run'),
        );
        $this->assertSame(
            ['limit_num' => 10, 'window_seconds' => 10],
            $config->resolveRouteLimits('/api/other'),
        );
        $this->assertNull($config->resolveRouteLimits('/health'));
    }
}
