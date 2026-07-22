<?php

declare(strict_types=1);

namespace PhpUintTest\Unit\Http;

use PhpUintTest\Support\HttpRequestHarness;
use PhpUintTest\TestCase;
use Swoole\Coroutine;
use Swoolefy\Core\Coroutine\Context;
use Swoolefy\Http\Middleware\LocaleMiddleware;

/**
 * LocaleMiddleware 语言协商（不启 HTTP 服务）。
 */
final class LocaleMiddlewareTest extends TestCase
{
    public function testQueryLangWins(): void
    {
        $locale = null;
        $ctx = null;
        Coroutine\run(static function () use (&$locale, &$ctx): void {
            $input = HttpRequestHarness::requestInput(
                'GET',
                '/api/x',
                ['lang' => 'en'],
                [],
                ['accept-language' => 'zh-CN'],
            );
            $locale = LocaleMiddleware::apply($input, [
                'allowed_locales' => ['zh_CN', 'en'],
                'default_locale' => 'zh_CN',
            ]);
            $ctx = Context::get(LocaleMiddleware::CONTEXT_KEY);
        });
        $this->assertSame('en', $locale);
        $this->assertSame('en', $ctx);
    }

    public function testAcceptLanguageFallback(): void
    {
        $locale = null;
        Coroutine\run(static function () use (&$locale): void {
            $input = HttpRequestHarness::requestInput(
                'GET',
                '/api/x',
                [],
                [],
                ['accept-language' => 'en-US,en;q=0.9'],
            );
            $locale = LocaleMiddleware::apply($input, [
                'allowed_locales' => ['zh_CN', 'en'],
                'default_locale' => 'zh_CN',
            ]);
        });
        $this->assertSame('en', $locale);
    }

    public function testDefaultWhenUnknown(): void
    {
        $locale = null;
        Coroutine\run(static function () use (&$locale): void {
            $input = HttpRequestHarness::requestInput(
                'GET',
                '/api/x',
                ['lang' => 'fr'],
            );
            $locale = LocaleMiddleware::apply($input, [
                'allowed_locales' => ['zh_CN', 'en'],
                'default_locale' => 'zh_CN',
            ]);
        });
        $this->assertSame('zh_CN', $locale);
    }
}
