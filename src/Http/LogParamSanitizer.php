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

/**
 * 请求参数调试日志脱敏：限制深度/字段数/字符串长度，遮蔽凭证类字段。
 */
final class LogParamSanitizer
{
    /** @var list<string> */
    public const DEFAULT_SENSITIVE_KEYS = [
        'password',
        'token',
        'authorization',
        'cookie',
        'secret',
        'credential',
    ];

    private const MAX_DEPTH = 5;

    private const MAX_FIELDS = 50;

    private const MAX_STRING_LENGTH = 256;

    private const REDACTED = '[REDACTED]';

    /**
     * @param mixed $data
     * @return mixed
     */
    public static function sanitize(mixed $data, int $depth = 0): mixed
    {
        if ($depth >= self::MAX_DEPTH) {
            return '[MAX_DEPTH]';
        }

        if (!is_array($data)) {
            return self::sanitizeScalar($data);
        }

        $result = [];
        $count = 0;
        foreach ($data as $key => $value) {
            if ($count >= self::MAX_FIELDS) {
                $result['__truncated__'] = true;
                break;
            }
            $count++;
            $keyString = is_string($key) ? $key : (string) $key;
            if (self::isSensitiveKey($keyString)) {
                $result[$key] = self::REDACTED;
                continue;
            }
            $result[$key] = self::sanitize($value, $depth + 1);
        }

        return $result;
    }

    private static function sanitizeScalar(mixed $value): mixed
    {
        if (is_string($value)) {
            if (strlen($value) > self::MAX_STRING_LENGTH) {
                return substr($value, 0, self::MAX_STRING_LENGTH) . '...[TRUNCATED]';
            }

            return $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }

        // 对象/资源不得进入调试日志
        return '[' . get_debug_type($value) . ']';
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', '_'], '', $key));
        foreach (self::DEFAULT_SENSITIVE_KEYS as $sensitive) {
            $needle = strtolower(str_replace(['-', '_'], '', $sensitive));
            if ($normalized === $needle || str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }
}
