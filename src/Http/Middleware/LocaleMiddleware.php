<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Swoolefy\Http\Middleware;

use Swoolefy\Core\Coroutine\Context;
use Swoolefy\Core\RouteMiddlewareInterface;
use Swoolefy\Http\RequestInput;
use Swoolefy\Http\ResponseOutput;

/**
 * 请求语言协商：解析 locale 写入协程 Context，供 translator 组件读取。
 *
 * ## 优先级（高 → 低）
 * 1. Query：`lang` / `locale`
 * 2. Header：`X-Locale` / `X-Language`
 * 3. Header：`Accept-Language`（取首个 tag）
 * 4. 配置 `default_locale`
 *
 * ## 注册（二选一）
 * ```php
 * // Bootstrap 全局（推荐）
 * LocaleMiddleware::apply($requestInput);
 *
 * // 或路由 middleware
 * Route::group(['middleware' => [LocaleMiddleware::class]], fn () => { ... });
 * ```
 *
 * ## 三行用法
 * ```php
 * LocaleMiddleware::apply($requestInput);                 // 1. 协商并写入 Context
 * $t = Application::getApp()->get('translator');          // 2. 取 Translator（读 lang_locale）
 * echo $t->trans('hello');                                // 3. 翻译
 * ```
 *
 * @see docs/I18n.md
 * @see Config/i18n.php
 */
class LocaleMiddleware implements RouteMiddlewareInterface
{
    public const CONTEXT_KEY = 'lang_locale';

    /**
     * @param array<string, mixed> $options 覆盖 Config/i18n.php（单测 / 定制）
     */
    public function __construct(private array $options = [])
    {
    }

    public function handle(RequestInput $requestInput, ResponseOutput $responseOutput): bool
    {
        self::apply($requestInput, $this->options);

        return true;
    }

    /**
     * Bootstrap / 路由共用入口。
     *
     * @param array<string, mixed> $options
     */
    public static function apply(RequestInput $requestInput, array $options = []): string
    {
        $config = self::mergeConfig($options);
        $locale = self::resolveLocale($requestInput, $config);
        $contextKey = (string) ($config['context_key'] ?? self::CONTEXT_KEY);
        Context::set($contextKey, $locale);

        return $locale;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private static function mergeConfig(array $options): array
    {
        $file = (defined('APP_PATH') ? APP_PATH : '') . '/Config/i18n.php';
        $fromFile = [];
        if ($file !== '/Config/i18n.php' && is_file($file)) {
            $loaded = include $file;
            $fromFile = is_array($loaded) ? $loaded : [];
        }

        return array_replace_recursive([
            'default_locale' => 'zh_CN',
            'fallback_locale' => 'en',
            'allowed_locales' => ['zh_CN', 'en'],
            'context_key' => self::CONTEXT_KEY,
            'query_keys' => ['lang', 'locale'],
            'header_keys' => ['x-locale', 'x-language'],
        ], $fromFile, $options);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function resolveLocale(RequestInput $requestInput, array $config): string
    {
        $allowed = $config['allowed_locales'] ?? ['zh_CN', 'en'];
        if (!is_array($allowed) || $allowed === []) {
            $allowed = ['zh_CN', 'en'];
        }
        $allowedMap = [];
        foreach ($allowed as $item) {
            $allowedMap[self::normalize((string) $item)] = (string) $item;
        }

        foreach ((array) ($config['query_keys'] ?? []) as $key) {
            $raw = $requestInput->input((string) $key);
            if (is_string($raw) && $raw !== '') {
                $hit = self::matchAllowed($raw, $allowedMap);
                if ($hit !== null) {
                    return $hit;
                }
            }
        }

        foreach ((array) ($config['header_keys'] ?? []) as $key) {
            $raw = $requestInput->getHeaderParams((string) $key);
            if (is_string($raw) && $raw !== '') {
                $hit = self::matchAllowed($raw, $allowedMap);
                if ($hit !== null) {
                    return $hit;
                }
            }
        }

        $accept = $requestInput->getHeaderParams('accept-language');
        if (is_string($accept) && $accept !== '') {
            foreach (self::parseAcceptLanguage($accept) as $tag) {
                $hit = self::matchAllowed($tag, $allowedMap);
                if ($hit !== null) {
                    return $hit;
                }
            }
        }

        $default = (string) ($config['default_locale'] ?? 'zh_CN');
        $normalized = self::normalize($default);

        return $allowedMap[$normalized] ?? $default;
    }

    /**
     * @param array<string, string> $allowedMap normalize => original
     */
    private static function matchAllowed(string $raw, array $allowedMap): ?string
    {
        $n = self::normalize($raw);
        if (isset($allowedMap[$n])) {
            return $allowedMap[$n];
        }
        // en-US → en
        if (str_contains($n, '_')) {
            $primary = explode('_', $n, 2)[0];
            if (isset($allowedMap[$primary])) {
                return $allowedMap[$primary];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function parseAcceptLanguage(string $header): array
    {
        $tags = [];
        foreach (explode(',', $header) as $part) {
            $part = trim(explode(';', $part, 2)[0]);
            if ($part !== '') {
                $tags[] = $part;
            }
        }

        return $tags;
    }

    private static function normalize(string $locale): string
    {
        $locale = str_replace('-', '_', trim($locale));

        return strtolower($locale);
    }
}
