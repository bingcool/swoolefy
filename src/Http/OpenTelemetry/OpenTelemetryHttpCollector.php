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

namespace Swoolefy\Http\OpenTelemetry;

use Swoolefy\Http\RouteOption;
use Swoolefy\Library\OpenTelemetry\SemConv\TraceAttributes;

/**
 * HTTP 请求 OTEL 采集决策与 attribute 组装（无 SDK 依赖，便于单测）。
 *
 * 开关语义：
 * - 全局关（OTEL_PHP_AUTOLOAD_ENABLED=false）→ 不采集
 * - 全局开 + 路由未关 → 采集
 * - 全局开 + 路由 enableOpenTelemetry(false) → 不采集
 */
final class OpenTelemetryHttpCollector
{
    /**
     * 是否应对当前请求创建 Span / 写入 attributes。
     */
    public static function shouldCollect(bool $globalEnabled, ?RouteOption $routeOption): bool
    {
        if (!$globalEnabled) {
            return false;
        }

        if ($routeOption !== null && !$routeOption->isEnableOpenTelemetry()) {
            return false;
        }

        return true;
    }

    /**
     * 组装即将写入 Span 的 attribute 映射（值为最终字符串）。
     *
     * @param array<string, mixed> $headers 已规范化（小写键）的请求头
     * @param array<string, mixed> $body    已合并的 post + JSON body
     * @param array<string, mixed> $query   GET 查询参数
     * @return array<string, string>
     */
    public static function buildAttributes(
        string $method,
        string $route,
        array $headers,
        array $body,
        array $query,
        OpenTelemetryConfig $config,
        ?int $coroutineId = null,
        ?string $serverAddress = null,
    ): array {
        $sanitize = $config->isSanitizeEnabled();
        $maxLength = $config->attributeMaxLength();

        if ($sanitize) {
            $headers = OpenTelemetryAttributeSanitizer::sanitize($headers);
            $body = OpenTelemetryAttributeSanitizer::sanitize($body);
            $query = OpenTelemetryAttributeSanitizer::sanitize($query);
        }

        $queryString = self::buildQueryString($query);
        $userAgent = self::headerValue($headers, 'user-agent', 'unknown');

        $attrs = [
            TraceAttributes::HTTP_REQUEST_METHOD => OpenTelemetryAttributeSanitizer::stringifyAndTruncate($method, $maxLength),
            TraceAttributes::URL_PATH => OpenTelemetryAttributeSanitizer::stringifyAndTruncate($route, $maxLength),
            TraceAttributes::HTTP_REQUEST_HEADERS => OpenTelemetryAttributeSanitizer::stringifyAndTruncate($headers, $maxLength),
            TraceAttributes::HTTP_REQUEST_QUERY_PARAMS => OpenTelemetryAttributeSanitizer::stringifyAndTruncate($queryString, $maxLength),
            TraceAttributes::HTTP_USER_AGENT => OpenTelemetryAttributeSanitizer::stringifyAndTruncate($userAgent, $maxLength),
            TraceAttributes::SERVER_ADDRESS => OpenTelemetryAttributeSanitizer::stringifyAndTruncate(
                $serverAddress ?? (string) gethostname(),
                $maxLength,
            ),
        ];

        if ($coroutineId !== null) {
            $attrs[TraceAttributes::COROUTINE_ID] = OpenTelemetryAttributeSanitizer::stringifyAndTruncate(
                (string) $coroutineId,
                $maxLength,
            );
        }

        // 默认采集 request body
        if ($config->collectRequestBody()) {
            $attrs[TraceAttributes::HTTP_REQUEST_BODY] = OpenTelemetryAttributeSanitizer::stringifyAndTruncate($body, $maxLength);
        }

        return $attrs;
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function buildQueryString(array $query): string
    {
        if ($query === []) {
            return '';
        }

        $parts = [];
        foreach ($query as $key => $value) {
            if (is_array($value)) {
                $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
                $parts[] = $key . '=' . ($encoded === false ? '' : $encoded);
                continue;
            }
            if (is_bool($value)) {
                $parts[] = $key . '=' . ($value ? '1' : '0');
                continue;
            }
            if ($value === null) {
                $parts[] = $key . '=';
                continue;
            }
            $parts[] = $key . '=' . (is_scalar($value) ? (string) $value : '');
        }

        return implode('&', $parts);
    }

    /**
     * @param array<string, mixed> $headers
     */
    private static function headerValue(array $headers, string $name, string $default): string
    {
        $key = strtolower($name);
        foreach ($headers as $headerName => $value) {
            if (strtolower((string) $headerName) !== $key) {
                continue;
            }

            return is_scalar($value) ? (string) $value : $default;
        }

        return $default;
    }
}
