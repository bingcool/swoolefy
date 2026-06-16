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

namespace Swoolefy\Http\Middleware;

use Swoolefy\Core\RouteMiddlewareInterface;
use Swoolefy\Core\SystemEnv;
use Swoolefy\Http\RequestInput;
use Swoolefy\Http\ResponseOutput;

/**
 * 生产环境 HTTP 安全响应头中间件。
 *
 * 在响应写出前注入一组安全基线 Header，降低 MIME 嗅探、点击劫持、信息泄露等常见 Web 风险。
 * 默认仅在 prd / gra 环境启用，dev/test 不注入，避免影响本地调试。
 *
 * 注册方式（二选一）：
 * 1. 路由分组 middleware（仅该分组路由生效）：
 *    Route::group(['middleware' => [SecurityHeadersMiddleware::class]], fn () => { ... });
 * 2. Bootstrap 全局（所有 HTTP 请求生效，推荐生产基线）：
 *    SecurityHeadersMiddleware::apply($requestInput, $responseOutput);
 *
 * 注意：须在控制器/SSE/文件下载结束响应之前执行，因此放在 before 中间件或 Bootstrap 中最合适。
 *
 * 环境变量：
 * - SECURITY_HEADERS_ENABLED=1|0          显式开关；未设置时 prd/gra 自动开启
 * - SECURITY_HEADERS_HSTS_MAX_AGE=31536000  HSTS 有效期（秒），仅 HTTPS 请求生效
 * - SECURITY_HEADERS_HSTS_INCLUDE_SUBDOMAINS=1|0
 * - SECURITY_HEADERS_FRAME_OPTIONS=DENY|SAMEORIGIN
 * - SECURITY_HEADERS_CSP=...              Content-Security-Policy，空字符串表示不发送
 * - SECURITY_HEADERS_REFERRER_POLICY=strict-origin-when-cross-origin
 * - SECURITY_HEADERS_PERMISSIONS_POLICY=geolocation=(), microphone=(), camera=()
 */
class SecurityHeadersMiddleware implements RouteMiddlewareInterface
{
    /**
     * @param array<string, mixed> $options 覆盖默认配置（常用于单测或按路由定制）
     *                                      支持键：enabled, frame_options, referrer_policy,
     *                                      permissions_policy, content_security_policy,
     *                                      hsts, hsts_max_age, hsts_include_subdomains
     */
    public function __construct(private array $options = [])
    {
    }

    /**
     * 路由中间件入口，委托给 apply() 统一处理。
     */
    public function handle(RequestInput $requestInput, ResponseOutput $responseOutput): bool
    {
        self::apply($requestInput, $responseOutput, $this->options);

        return true;
    }

    /**
     * 静态入口，供 Bootstrap 或业务代码直接调用。
     *
     * @param array<string, mixed> $options
     */
    public static function apply(RequestInput $requestInput, ResponseOutput $responseOutput, array $options = []): void
    {
        $middleware = new self($options);
        if (!$middleware->shouldApply()) {
            return;
        }

        // 在业务写 body 之前设置响应头；Swoole 允许 header() 在 write/end 之前多次调用
        foreach ($middleware->buildHeaders($requestInput) as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $responseOutput->withHeader($name, $value);
        }
    }

    /**
     * 是否启用安全头。
     *
     * 优先级：构造函数 options['enabled'] > env SECURITY_HEADERS_ENABLED > 环境判断（prd/gra）。
     */
    protected function shouldApply(): bool
    {
        if (array_key_exists('enabled', $this->options)) {
            return (bool) $this->options['enabled'];
        }

        $enabled = env('SECURITY_HEADERS_ENABLED', null);
        if ($enabled !== null && $enabled !== '') {
            return filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
        }

        // 生产/灰度默认开启；开发环境可通过 SECURITY_HEADERS_ENABLED=1 手动验证
        return SystemEnv::isPrdEnv() || SystemEnv::isGraEnv();
    }

    /**
     * 组装本中间件负责的全部安全响应头。
     *
     * @return array<string, string|null>
     */
    protected function buildHeaders(RequestInput $requestInput): array
    {
        $headers = [
            // 禁止浏览器对 Content-Type 做嗅探，降低把 JSON/文本当脚本执行的风险
            'X-Content-Type-Options' => 'nosniff',
            // 降低页面被嵌入 iframe 导致的点击劫持风险
            'X-Frame-Options' => $this->resolveFrameOptions(),
            // 控制 Referer 泄露范围，避免跨站携带完整 URL
            'Referrer-Policy' => $this->resolveReferrerPolicy(),
            // 限制 Flash/PDF 等跨域策略文件
            'X-Permitted-Cross-Domain-Policies' => 'none',
        ];

        $permissionsPolicy = $this->resolvePermissionsPolicy();
        if ($permissionsPolicy !== '') {
            // 限制浏览器能力（定位、麦克风、摄像头等）的默认授权
            $headers['Permissions-Policy'] = $permissionsPolicy;
        }

        $csp = $this->resolveContentSecurityPolicy();
        if ($csp !== '') {
            // 限制资源加载来源；纯 API 默认较严格，有前端页面时建议通过 env 放宽
            $headers['Content-Security-Policy'] = $csp;
        }

        // HSTS 仅在 HTTPS 下发送：若 http 站点误配，浏览器会强制跳 https 导致不可用
        if ($requestInput->isSsl()) {
            $hsts = $this->resolveStrictTransportSecurity();
            if ($hsts !== '') {
                $headers['Strict-Transport-Security'] = $hsts;
            }
        }

        return $headers;
    }

    /**
     * X-Frame-Options：DENY 完全禁止嵌入；SAMEORIGIN 仅同源可嵌入。
     */
    protected function resolveFrameOptions(): string
    {
        if (isset($this->options['frame_options'])) {
            return (string) $this->options['frame_options'];
        }

        $value = strtoupper((string) env('SECURITY_HEADERS_FRAME_OPTIONS', 'DENY'));

        // 非法值回退 DENY，避免配置错误导致防护失效
        return in_array($value, ['DENY', 'SAMEORIGIN'], true) ? $value : 'DENY';
    }

    /**
     * Referrer-Policy：默认 strict-origin-when-cross-origin，兼顾安全与常见跳转场景。
     */
    protected function resolveReferrerPolicy(): string
    {
        if (isset($this->options['referrer_policy'])) {
            return (string) $this->options['referrer_policy'];
        }

        return (string) env('SECURITY_HEADERS_REFERRER_POLICY', 'strict-origin-when-cross-origin');
    }

    /**
     * Permissions-Policy：默认关闭定位/麦克风/摄像头，API 服务通常不需要这些能力。
     */
    protected function resolvePermissionsPolicy(): string
    {
        if (array_key_exists('permissions_policy', $this->options)) {
            return (string) $this->options['permissions_policy'];
        }

        return (string) env(
            'SECURITY_HEADERS_PERMISSIONS_POLICY',
            'geolocation=(), microphone=(), camera=()'
        );
    }

    /**
     * Content-Security-Policy。
     *
     * env 设为空字符串可完全关闭 CSP；未配置时使用 API 友好默认值（不加载任何外部资源）。
     */
    protected function resolveContentSecurityPolicy(): string
    {
        if (array_key_exists('content_security_policy', $this->options)) {
            return (string) $this->options['content_security_policy'];
        }

        $csp = env('SECURITY_HEADERS_CSP', null);
        if ($csp !== null) {
            return (string) $csp;
        }

        return "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'";
    }

    /**
     * Strict-Transport-Security（HSTS）。
     *
     * max-age<=0 时不发送；includeSubDomains 可通过 env 关闭（多租户或混合 http 子域场景）。
     */
    protected function resolveStrictTransportSecurity(): string
    {
        if (array_key_exists('hsts', $this->options)) {
            return (string) $this->options['hsts'];
        }

        $maxAge = (int) ($this->options['hsts_max_age'] ?? env('SECURITY_HEADERS_HSTS_MAX_AGE', 31536000));
        if ($maxAge <= 0) {
            return '';
        }

        $includeSubDomains = $this->options['hsts_include_subdomains']
            ?? filter_var(env('SECURITY_HEADERS_HSTS_INCLUDE_SUBDOMAINS', '1'), FILTER_VALIDATE_BOOLEAN);

        $value = 'max-age=' . $maxAge;
        if ($includeSubDomains) {
            $value .= '; includeSubDomains';
        }

        return $value;
    }
}
