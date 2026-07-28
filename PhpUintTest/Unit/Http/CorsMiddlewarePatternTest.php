<?php

declare(strict_types=1);

namespace PhpUintTest\Unit\Http;

use PhpUintTest\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Swoolefy\Http\Middleware\CorsMiddleware;

/**
 * CORS allowedOriginsPatterns：域名须 preg_quote，拒绝非法 URL 形态。
 */
final class CorsMiddlewarePatternTest extends TestCase
{
    public function testBuildPatternsQuotesDotsAndMatchesSubdomains(): void
    {
        $mw = new CorsMiddleware();
        $refOptions = new ReflectionProperty(CorsMiddleware::class, 'options');
        $refOptions->setAccessible(true);
        $options = $refOptions->getValue($mw);
        $options['allowedOriginsPatterns'] = ['*.example.com'];
        $refOptions->setValue($mw, $options);

        // 避免 env 覆盖测试配置
        putenv('CORS_ALLOWED_ORIGINS_PATTERNS');
        $_ENV['CORS_ALLOWED_ORIGINS_PATTERNS'] = '';
        $_SERVER['CORS_ALLOWED_ORIGINS_PATTERNS'] = '';

        $method = new ReflectionMethod(CorsMiddleware::class, 'buildAllowedOriginsPatterns');
        $method->setAccessible(true);
        $method->invoke($mw);

        $patterns = $refOptions->getValue($mw)['allowedOriginsPatterns'];
        $this->assertNotEmpty($patterns);

        $matched = false;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, 'https://api.example.com') === 1) {
                $matched = true;
            }
            // 点号已转义：exampleXcom 不应匹配
            $this->assertSame(0, preg_match($pattern, 'https://exampleXcom'));
        }
        $this->assertTrue($matched);
    }

    public function testRejectsSchemePathAndPortInPatternConfig(): void
    {
        $mw = new CorsMiddleware();
        $normalize = new ReflectionMethod(CorsMiddleware::class, 'normalizeAllowedOriginPatternDomain');
        $normalize->setAccessible(true);

        $this->assertNull($normalize->invoke($mw, 'https://evil.com'));
        $this->assertNull($normalize->invoke($mw, 'example.com/path'));
        $this->assertNull($normalize->invoke($mw, 'example.com:443'));
        $this->assertSame('example.com', $normalize->invoke($mw, '*.example.com'));
        $this->assertSame('example.com', $normalize->invoke($mw, 'example.com'));
    }
}
