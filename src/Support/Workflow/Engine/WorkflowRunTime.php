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

namespace Swoolefy\Support\Workflow\Engine;

/**
 * Workflow Run 时间戳与 runId 生成（与 DB DATETIME 字段对齐）。
 */
final class WorkflowRunTime
{
    public const DATETIME_FORMAT = 'Y-m-d H:i:s';

    public static function now(): string
    {
        return date(self::DATETIME_FORMAT);
    }

    /**
     * run_{YYYYMMDD}_{random}
     */
    public static function generateRunId(): string
    {
        return 'run_' . date('Ymd') . '_' . bin2hex(random_bytes(8));
    }

    /** @param int|string|null $value 兼容历史 Unix 时间戳或 DATETIME 字符串 */
    public static function normalize(int|string|null $value = null): string
    {
        if ($value === null || $value === '') {
            return self::now();
        }

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return date(self::DATETIME_FORMAT, (int) $value);
        }

        return (string) $value;
    }
}
