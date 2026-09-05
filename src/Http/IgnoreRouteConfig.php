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

namespace Swoolefy\Http;

use Swoole\Http\Request;
use Swoolefy\Support\ApplicationConfig;

/**
 * HTTP 忽略路由配置（APP_PATH/Config/ignore_route.php）。
 *
 * 用于静默处理浏览器或探针的默认请求，避免进入路由分发后报 Not Found。
 */
final class IgnoreRouteConfig
{
    /** @var list<string> */
    private const DEFAULT_ROUTES = [
        '/favicon.ico',
        '/.well-known/appspecific/com.chrome.devtools.json',
    ];

    /** @var self|null */
    private static ?self $instance = null;

    /** @param list<string> $routes */
    /** @param list<string> $prefixes */
    private function __construct(
        private readonly array $routes,
        private readonly array $prefixes,
    ) {
    }

    public static function load(): self
    {
        if (self::$instance instanceof self) {
            return self::$instance;
        }

        $config = ApplicationConfig::loadPhpConfig('ignore_route.php');
        $routes = self::normalizeList($config['routes'] ?? []);
        $prefixes = self::normalizeList($config['prefixes'] ?? []);

        self::$instance = new self(
            array_values(array_unique(array_merge(self::DEFAULT_ROUTES, $routes))),
            $prefixes,
        );

        return self::$instance;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @internal 单测注入
     */
    public static function fromArray(array $config): self
    {
        return new self(
            array_values(array_unique(array_merge(
                self::DEFAULT_ROUTES,
                self::normalizeList($config['routes'] ?? []),
            ))),
            self::normalizeList($config['prefixes'] ?? []),
        );
    }

    public static function clearCache(): void
    {
        self::$instance = null;
    }

    public static function shouldIgnore(Request $request): bool
    {
        $paths = self::resolveRequestPaths($request);
        if ($paths === []) {
            return false;
        }

        $config = self::load();

        foreach ($paths as $path) {
            if ($config->matches($path)) {
                return true;
            }
        }

        return false;
    }

    private function matches(string $path): bool
    {
        if (in_array($path, $this->routes, true)) {
            return true;
        }

        foreach ($this->prefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function resolveRequestPaths(Request $request): array
    {
        $paths = [];
        foreach (['path_info', 'request_uri'] as $key) {
            $path = self::normalizePath($request->server[$key] ?? null);
            if ($path !== null && $path !== '') {
                $paths[] = $path;
            }
        }

        return array_values(array_unique($paths));
    }

    private static function normalizePath(mixed $uri): ?string
    {
        if (!is_string($uri) || $uri === '') {
            return null;
        }

        $path = parse_url($uri, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : $uri;
    }

    /**
     * @param mixed $items
     *
     * @return list<string>
     */
    private static function normalizeList(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $item) {
            if (!is_string($item) || $item === '') {
                continue;
            }
            $normalized[] = $item;
        }

        return $normalized;
    }
}
