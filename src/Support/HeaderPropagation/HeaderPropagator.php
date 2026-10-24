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

namespace Swoolefy\Support\HeaderPropagation;

use Swoolefy\Core\Coroutine\Context as SwooleContext;
use Swoolefy\Library\CurlProxy\OpentelemetryMiddleware;
use Swoolefy\Util\IpUtils;

/**
 * 上游请求头透传白名单。
 *
 * 只透传内部可信 Header，避免把 Cookie、Authorization 等用户侧敏感头无控制地下发。
 */
final class HeaderPropagator
{
    public const HEADER_TRACE_ID = 'x-trace-id';
    public const HEADER_USER_ID = 'x-user-id';
    public const HEADER_USER_CODE = 'x-user-code';
    public const HEADER_TENANT_ID = 'x-tenant-id';
    public const HEADER_USER_NAME = 'x-user-name';
    public const HEADER_CLIENT_IP = 'x-client-ip';
    public const HEADER_USER_AGENT = 'x-user-agent';

    private const DEFAULT_USER_AGENT = 'swoolefy-api-sdk';

    private const PROPAGATE_HEADERS = [
        self::HEADER_TRACE_ID,
        self::HEADER_USER_ID,
        self::HEADER_USER_CODE,
        self::HEADER_TENANT_ID,
        self::HEADER_USER_NAME,
        self::HEADER_CLIENT_IP,
        self::HEADER_USER_AGENT,
    ];

    /**
     * 只允许可信代理写入的身份头。公网直连请求携带的同名头一律丢弃，
     * 避免客户端用 x-tenant-id 冒充其他租户。
     */
    private const FORWARDED_IDENTITY_HEADERS = [
        self::HEADER_USER_ID,
        self::HEADER_USER_CODE,
        self::HEADER_TENANT_ID,
        self::HEADER_USER_NAME,
    ];

    /**
     * 从入口请求 Header 中提取白名单，并兼容已有协程 trace id。
     *
     * 身份头只在 $remoteAddr 命中 $trustedProxies 时采信；否则视为不可信直连，
     * 丢弃 x-user-id / x-user-code / x-tenant-id / x-user-name，并用直连地址覆盖 x-client-ip。
     * 可信代理必须覆盖而不是追加客户端传入的身份头，否则伪造值仍会被下游采信。
     *
     * @param array<string, mixed> $headers
     * @param list<string> $trustedProxies IP 或 CIDR；空数组表示不信任任何来源
     * @return array<string, string>
     */
    public static function captureIncoming(
        array $headers,
        ?string $traceId = null,
        array $trustedProxies = [],
        string $remoteAddr = '',
    ): array {
        $normalized = self::normalize($headers);
        $captured = [];
        foreach (self::PROPAGATE_HEADERS as $name) {
            if (isset($normalized[$name]) && '' !== $normalized[$name]) {
                $captured[$name] = $normalized[$name];
            }
        }

        if (!self::isTrustedForwardedIdentitySource($trustedProxies, $remoteAddr)) {
            foreach (self::FORWARDED_IDENTITY_HEADERS as $name) {
                unset($captured[$name]);
            }
            $remoteAddr = trim($remoteAddr);
            if ('' !== $remoteAddr) {
                $captured[self::HEADER_CLIENT_IP] = $remoteAddr;
            }
        }

        $traceId = $traceId ?: self::currentTraceId();
        if ('' !== $traceId) {
            $captured[self::HEADER_TRACE_ID] = $traceId;
        }

        return $captured;
    }

    /**
     * 连接来源是否在可信代理列表中。
     *
     * @param list<string> $trustedProxies
     */
    public static function isTrustedForwardedIdentitySource(array $trustedProxies, string $remoteAddr): bool
    {
        return IpUtils::isTrustedProxyRemoteIp($remoteAddr, $trustedProxies);
    }

    /**
     * SDK 下游请求头：当前上下文白名单 + trace id + 默认 SDK user-agent。
     *
     * @return array<string, string>
     */
    public static function outgoingHeaders(): array
    {
        $headers = HeaderContext::all();
        $traceId = self::currentTraceId();
        if ('' !== $traceId) {
            $headers[self::HEADER_TRACE_ID] = $traceId;
        }

        // 标识这是 swoolefy 生成 SDK 发起的服务间调用；业务显式传入 headers 时仍可覆盖。
        $headers[self::HEADER_USER_AGENT] = self::DEFAULT_USER_AGENT;

        return $headers;
    }

    /**
     * @param array<string, mixed> $headers
     * @return array<string, string>
     */
    private static function normalize(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            if (is_array($value)) {
                $value = implode(',', array_map('strval', $value));
            }
            if (!is_scalar($value)) {
                continue;
            }

            $normalized[strtolower((string) $name)] = trim((string) $value);
        }

        return $normalized;
    }

    private static function currentTraceId(): string
    {
        try {
            $context = SwooleContext::getContext();
        } catch (\Throwable) {
            return '';
        }

        if (!$context instanceof \ArrayObject || !isset($context[OpentelemetryMiddleware::OPENTELEMETRY_X_TRACE_ID])) {
            return '';
        }

        return (string) $context[OpentelemetryMiddleware::OPENTELEMETRY_X_TRACE_ID];
    }
}
