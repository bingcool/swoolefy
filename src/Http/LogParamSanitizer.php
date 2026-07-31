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
 * 请求参数调试日志脱敏器
 *
 * 职责：在缺参异常、调试日志等场景记录请求参数前，统一遮蔽凭证类字段，
 * 并限制递归深度、字段数量与字符串长度，避免敏感值与过大载荷进入响应/日志。
 *
 * 使用场景：
 * - 缺参等异常路径需附加 post/actionParams 时（如 SwoolefyException::response）
 * - HttpRoute 等调试侧若需记录已绑定参数，须经本类 sanitize，禁止直接 json_encode
 *
 * 调试侧仅允许经过本脱敏器，默认遮蔽 password/token/authorization/cookie/secret/credential。
 *
 * 调用方注意：
 * - 本类只做「可安全落日志」的数据变换，不负责开关（如 ENABLE_LOG_SANITIZE）
 * - 脱敏后仍可能保留非敏感业务字段，勿当作加密或权限控制
 * - 深度/字段数/长度触顶时返回占位标记（[MAX_DEPTH]、__truncated__、...[TRUNCATED]），非原值
 */
final class LogParamSanitizer
{
    /**
     * 默认敏感键名（匹配前会规范化：小写并去掉 -/_）。
     * 键名包含任一敏感词子串即视为敏感（如 access_token、api_secret）。
     *
     * @var list<string>
     */
    public const DEFAULT_SENSITIVE_KEYS = [
        'password',
        'token',
        'authorization',
        'cookie',
        'secret',
        'credential',
    ];

    /** 数组最大递归深度（从 0 起计；达到后不再展开，返回 [MAX_DEPTH]） */
    private const MAX_DEPTH = 3;

    /** 同一层数组最多保留的字段数，超出后打 __truncated__ 并停止遍历 */
    private const MAX_FIELDS = 200;

    /** 字符串值最大保留字节长度（strlen），超出截断并追加 ...[TRUNCATED] */
    private const MAX_STRING_LENGTH = 1024;

    /** 敏感键对应值的统一占位 */
    private const REDACTED = '[REDACTED]';

    /**
     * 递归脱敏：数组按键判断敏感并限制深度/字段数，标量走 sanitizeScalar。
     *
     * @param mixed $data  待脱敏数据（通常为请求 post / actionParams 等数组）
     * @param int   $depth 当前递归深度，外部调用保持默认 0；内部自增
     * @return mixed 脱敏后的结构：敏感值变为 [REDACTED]；超深为 [MAX_DEPTH]；
     *               字段过多带 __truncated__=>true；超长字符串带截断后缀
     */
    public static function sanitize(mixed $data, int $depth = 0): mixed
    {
        // depth 从 0 起，第 3 层嵌套再下钻即触顶
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

    /**
     * 标量与非数组值的安全化：短字符串原样返回，超长截断；对象/资源仅留类型名。
     *
     * @param mixed $value 非数组节点（string/int/float/bool/null 或其它）
     * @return mixed 可安全写入日志的表示；对象/资源为 "[TypeName]"
     */
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

    /**
     * 判断字段名是否敏感：规范化后与 DEFAULT_SENSITIVE_KEYS 全等或子串包含即命中。
     *
     * 规范化规则：strtolower，并去掉键名中的 '-'、'_'，
     * 故 Password、access_token、API-Secret、user_credential 等均会命中。
     *
     * @param string $key 数组键名（非字符串键已在调用处转为 string）
     * @return bool true 表示对应值应替换为 [REDACTED]
     */
    private static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', '_'], '', $key));
        foreach (self::DEFAULT_SENSITIVE_KEYS as $sensitive) {
            $needle = strtolower(str_replace(['-', '_'], '', $sensitive));
            // 全等或包含敏感词（覆盖嵌套命名如 access_token）
            if ($normalized === $needle || str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }
}
