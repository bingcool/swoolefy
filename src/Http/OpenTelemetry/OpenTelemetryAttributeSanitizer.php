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

/**
 * OTEL attribute 脱敏与长度截断。
 *
 * 对齐 {@see \Swoolefy\Http\LogParamSanitizer} 的敏感键思路，适配 header/body/query：
 * - Authorization、Cookie、Set-Cookie、Token、密码和凭证类字段 → `[REDACTED]`
 * - attribute 最大长度由调用方传入；未设置（null）时不截断
 */
final class OpenTelemetryAttributeSanitizer
{
    /**
     * 默认敏感键名（匹配前规范化：小写并去掉 -/_）。
     * 键名包含任一敏感词子串即视为敏感。
     *
     * @var list<string>
     */
    public const DEFAULT_SENSITIVE_KEYS = [
        'password',
        'token',
        'authorization',
        'cookie',
        'setcookie',
        'secret',
        'credential',
    ];

    private const REDACTED = '[REDACTED]';

    private const TRUNCATED_SUFFIX = '...[TRUNCATED]';

    private const MAX_DEPTH = 5;

    private const MAX_FIELDS = 200;

    /**
     * 递归脱敏数组键（header / body / query）。
     *
     * @param mixed $data
     * @return mixed
     */
    public static function sanitize(mixed $data, int $depth = 0): mixed
    {
        if ($depth >= self::MAX_DEPTH) {
            return '[MAX_DEPTH]';
        }

        if (!is_array($data)) {
            return $data;
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

    /**
     * 将 attribute 值规范为字符串，并按最大长度截断。
     *
     * @param mixed $value 标量或已可 json 编码的结构
     * @param int|null $maxLength null / ≤0 表示不截断
     */
    public static function stringifyAndTruncate(mixed $value, ?int $maxLength): string
    {
        if (is_string($value)) {
            $string = $value;
        } elseif (is_array($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
            $string = $encoded === false ? '' : $encoded;
        } elseif (is_bool($value)) {
            $string = $value ? 'true' : 'false';
        } elseif ($value === null) {
            $string = '';
        } elseif (is_scalar($value)) {
            $string = (string) $value;
        } else {
            $string = '[' . get_debug_type($value) . ']';
        }

        return self::truncate($string, $maxLength);
    }

    /**
     * 超出最大长度时截断并追加 `...[TRUNCATED]`。
     */
    public static function truncate(string $value, ?int $maxLength): string
    {
        if ($maxLength === null || $maxLength <= 0) {
            return $value;
        }

        if (strlen($value) <= $maxLength) {
            return $value;
        }

        $suffixLen = strlen(self::TRUNCATED_SUFFIX);
        if ($maxLength <= $suffixLen) {
            return substr(self::TRUNCATED_SUFFIX, 0, $maxLength);
        }

        return substr($value, 0, $maxLength - $suffixLen) . self::TRUNCATED_SUFFIX;
    }

    public static function isSensitiveKey(string $key): bool
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
