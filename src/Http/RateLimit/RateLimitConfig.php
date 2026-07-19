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

namespace Swoolefy\Http\RateLimit;

use Swoolefy\Support\ApplicationConfig;

/**
 * HTTP RateLimit 配置读取器（APP_PATH/Config/rate_limit.php）。
 *
 * ## 配置来源优先级（各字段）
 * 环境变量（`RATE_LIMIT_*`）→ PHP 配置文件 → 代码默认值。
 * 使用 {@see ApplicationConfig::pick*EnvFirst}，与 Auth / Job / Workflow 配置风格一致。
 *
 * ## 模版
 * `src/Stubs/rate_limit.conf.stub.php`（`create` 命令复制为 `Config/rate_limit.php`）。
 *
 * ## 与 RouteOption 的关系
 * 路由上 `withRateLimiterMiddleware($class, $limitNum, $window)` 注入的数值**高于**本文件
 * `routes` / `default_*`；本类只负责配置文件侧解析。
 */
final class RateLimitConfig
{
    /**
     * @param array<string, mixed> $config 完整配置数组（可含顶层 `rate_limit` 包装）
     */
    private function __construct(
        private readonly array $config,
    ) {
    }

    /**
     * 从应用 Config 目录加载 `rate_limit.php`。
     * 文件不存在时返回空数组包装实例（各 getter 走默认值）。
     */
    public static function load(): self
    {
        return new self(ApplicationConfig::loadPhpConfig('rate_limit.php'));
    }

    /**
     * 用内存数组构造（单测 / 脚本注入，不读磁盘）。
     *
     * @param array<string, mixed> $config
     *
     * @internal
     */
    public static function fromArray(array $config): self
    {
        return new self($config);
    }

    /**
     * 取出 `rate_limit` 段；若调用方直接传扁平数组则原样使用。
     *
     * @return array<string, mixed>
     */
    private function section(): array
    {
        $section = $this->config['rate_limit'] ?? $this->config;

        return is_array($section) ? $section : [];
    }

    /**
     * 总开关。false 时中间件 {@see \Swoolefy\Http\Middleware\AbstractRateLimiterMiddleware::handle}
     * 直接放行，不访问 Redis。
     *
     * env: `RATE_LIMIT_ENABLED`
     */
    public function enabled(): bool
    {
        return ApplicationConfig::pickBoolEnvFirst(
            $this->section(),
            'enabled',
            'RATE_LIMIT_ENABLED',
            true,
        );
    }

    /**
     * Application 容器组件名，默认 `redis`。
     * 解析时若组件带 `getObject()`（协程包装），会先 unwrap 再交给 DurationLimiter。
     *
     * env: `RATE_LIMIT_REDIS_COMPONENT`
     */
    public function redisComponent(): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->section(),
            'redis_component',
            'RATE_LIMIT_REDIS_COMPONENT',
            'redis',
        );
    }

    /**
     * 逻辑键前缀（业务侧），最终 Redis key 仍会再加 DurationLimiter 的 `_rate_limit:`。
     * 多应用共用 Redis 时可改此前缀做隔离。
     *
     * env: `RATE_LIMIT_KEY_PREFIX`
     */
    public function keyPrefix(): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->section(),
            'key_prefix',
            'RATE_LIMIT_KEY_PREFIX',
            'http:',
        );
    }

    /**
     * 默认窗口内最大请求数（至少为 1）。
     * 在未设置 RouteOption、也未匹配 `routes` 时生效。
     *
     * env: `RATE_LIMIT_DEFAULT_NUM`
     */
    public function defaultLimitNum(): int
    {
        return max(1, ApplicationConfig::pickIntEnvFirst(
            $this->section(),
            'default_limit_num',
            'RATE_LIMIT_DEFAULT_NUM',
            60,
        ));
    }

    /**
     * 默认滑动窗口秒数（至少为 1）。
     *
     * env: `RATE_LIMIT_DEFAULT_WINDOW`
     */
    public function defaultWindowSeconds(): int
    {
        return max(1, ApplicationConfig::pickIntEnvFirst(
            $this->section(),
            'default_window_seconds',
            'RATE_LIMIT_DEFAULT_WINDOW',
            60,
        ));
    }

    /**
     * 触发限流时的 HTTP 状态码（至少 400，默认 429）。
     *
     * env: `RATE_LIMIT_HTTP_STATUS`
     */
    public function httpStatus(): int
    {
        return max(400, ApplicationConfig::pickIntEnvFirst(
            $this->section(),
            'http_status',
            'RATE_LIMIT_HTTP_STATUS',
            429,
        ));
    }

    /**
     * 触发限流时的响应文案（进入异常 message）。
     *
     * env: `RATE_LIMIT_MESSAGE`
     */
    public function message(): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->section(),
            'message',
            'RATE_LIMIT_MESSAGE',
            'Too Many Requests',
        );
    }

    /**
     * 是否写入 `X-RateLimit-*` / `Retry-After` 响应头。
     *
     * env: `RATE_LIMIT_ADD_HEADERS`
     */
    public function addHeaders(): bool
    {
        return ApplicationConfig::pickBoolEnvFirst(
            $this->section(),
            'add_headers',
            'RATE_LIMIT_ADD_HEADERS',
            true,
        );
    }

    /**
     * 按请求 path 匹配 `routes` 覆盖项，解析出 limit / window。
     *
     * ## 匹配规则
     * - 精确：`/api/rate-test1`
     * - 前缀：以 `*` 结尾，如 `/api/v1/workflow/*`
     * - 多条同时命中时取**最长前缀**（更具体的规则优先）
     *
     * ## 字段别名
     * `limit_num` / `limit`，`window_seconds` / `window` 均可。
     *
     * @param string $path 已规范化的 path（建议以 `/` 开头）
     *
     * @return array{limit_num: int, window_seconds: int}|null 未命中或数值非法时 null
     */
    public function resolveRouteLimits(string $path): ?array
    {
        $routes = $this->section()['routes'] ?? [];
        if (!is_array($routes) || $routes === []) {
            return null;
        }

        // 统一 path 形态，避免配置写 `/api/x`、请求却是 `api/x` 导致匹配失败
        $path = '/' . ltrim($path, '/');
        $matched = null;
        $matchedLen = -1;

        foreach ($routes as $pattern => $rule) {
            if (!is_string($pattern) || !is_array($rule)) {
                continue;
            }
            if (!$this->pathMatches($path, $pattern)) {
                continue;
            }
            // 用去掉尾部 * 后的长度衡量「具体程度」
            $len = strlen(rtrim($pattern, '*'));
            if ($len >= $matchedLen) {
                $matchedLen = $len;
                $matched = $rule;
            }
        }

        if ($matched === null) {
            return null;
        }

        $limit = (int) ($matched['limit_num'] ?? $matched['limit'] ?? 0);
        $window = (int) ($matched['window_seconds'] ?? $matched['window'] ?? 0);
        // 0 或负数视为无效配置，回退到更高优先级来源 / 默认值
        if ($limit <= 0 || $window <= 0) {
            return null;
        }

        return ['limit_num' => $limit, 'window_seconds' => $window];
    }

    /**
     * `rate_limit.user` 子段（仅 ApiUserRateLimiter 使用）。
     *
     * @return array<string, mixed>
     */
    public function userSection(): array
    {
        $user = $this->section()['user'] ?? [];

        return is_array($user) ? $user : [];
    }

    /**
     * 匿名且拿不到 ClientIP 时的 subject 占位（写入 key：`anon:{此值}`）。
     *
     * env: `RATE_LIMIT_ANONYMOUS_KEY`
     */
    public function anonymousKey(): string
    {
        $user = $this->userSection();

        return ApplicationConfig::pickStringEnvFirst(
            $user,
            'anonymous_key',
            'RATE_LIMIT_ANONYMOUS_KEY',
            'guest',
        );
    }

    /**
     * 未登录时是否用 ClientIP 作为用户维 subject（`ip:{ip}`）。
     * false 则直接用 {@see anonymousKey()}。
     *
     * env: `RATE_LIMIT_USE_IP_WHEN_ANONYMOUS`
     */
    public function useClientIpWhenAnonymous(): bool
    {
        return ApplicationConfig::pickBoolEnvFirst(
            $this->userSection(),
            'use_client_ip_when_anonymous',
            'RATE_LIMIT_USE_IP_WHEN_ANONYMOUS',
            true,
        );
    }

    /**
     * true：无本地验票用户（{@see \Swoolefy\Support\FrameworkContext::user()}）时，
     * ApiUserRateLimiter **跳过**限流（适合「强制登录路由、匿名不应打到此中间件」的场景）。
     * false（默认）：匿名仍按 IP / guest 计数。
     *
     * env: `RATE_LIMIT_SKIP_WHEN_UNAUTHENTICATED`
     */
    public function skipWhenUnauthenticated(): bool
    {
        return ApplicationConfig::pickBoolEnvFirst(
            $this->userSection(),
            'skip_when_unauthenticated',
            'RATE_LIMIT_SKIP_WHEN_UNAUTHENTICATED',
            false,
        );
    }

    /**
     * path 是否命中配置中的 pattern。
     *
     * @param string $path    请求 path（已带前导 `/`）
     * @param string $pattern 配置键，支持尾部 `*`
     */
    private function pathMatches(string $path, string $pattern): bool
    {
        $pattern = '/' . ltrim($pattern, '/');
        if (str_ends_with($pattern, '*')) {
            $prefix = substr($pattern, 0, -1);

            return str_starts_with($path, $prefix);
        }

        return $path === $pattern;
    }
}
