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

namespace Swoolefy\Worker\Cron;

/**
 * Cron Agent 节点存活判定（生产公式，Admin / Dashboard / Worker 共用）。
 *
 * online 当且仅当 last_heartbeat_at 非空，且
 * `(now - last_heartbeat_at) <= max(3 * interval, interval + 5)`；
 * 否则 offline。允许约 2 次漏跳 + 抖动；从未心跳不得视为 online。
 *
 * 多节点可有不同 heartbeat_interval：Ack 时写入 cron_agent_node.heartbeat_interval，
 * Admin 用该节点自己的间隔，而不是全局默认。
 *
 * @see CronManager::start()
 * @see \Test\Module\Cron\Dto\CronTaskManager\CronAgentNodeRowDto
 */
final class CronNodeLiveness
{
    /** 节点在线：最近心跳仍在阈值内。 */
    public const STATUS_ONLINE = 'online';

    /** 节点离线：无心跳或已超过阈值。 */
    public const STATUS_OFFLINE = 'offline';

    /** Worker args heartbeat_interval 缺省秒数。 */
    public const DEFAULT_INTERVAL = 15;

    /**
     * 非法 / 缺省间隔回退为 {@see DEFAULT_INTERVAL}。
     */
    public static function normalizeInterval(int $interval): int
    {
        return $interval < 1 ? self::DEFAULT_INTERVAL : $interval;
    }

    /**
     * 超过该秒数未心跳则 offline：max(3 * interval, interval + 5)。
     */
    public static function staleAfterSeconds(int $interval): int
    {
        $interval = self::normalizeInterval($interval);

        return max(3 * $interval, $interval + 5);
    }

    /**
     * 将 DB datetime / unix 秒解析为 unix 秒；空 / 非法返回 null。
     */
    public static function parseHeartbeatAt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_float($value)) {
            $ts = (int) $value;

            return $ts > 0 ? $ts : null;
        }
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        if (ctype_digit($trimmed)) {
            $ts = (int) $trimmed;

            return $ts > 0 ? $ts : null;
        }
        $ts = strtotime($trimmed);

        return $ts === false ? null : $ts;
    }

    /**
     * 生产存活状态：只返回 online | offline。
     *
     * @param int $now 当前 unix 秒
     * @param int|null $lastHeartbeatAt 最近心跳 unix 秒；null 视为从未心跳
     * @param int $interval 该节点自己的心跳间隔（秒）
     */
    public static function status(int $now, ?int $lastHeartbeatAt, int $interval): string
    {
        if ($lastHeartbeatAt === null || $lastHeartbeatAt <= 0) {
            return self::STATUS_OFFLINE;
        }
        $age = $now - $lastHeartbeatAt;
        if ($age < 0) {
            // 时钟回拨：仍视为刚心跳过
            return self::STATUS_ONLINE;
        }

        return $age <= self::staleAfterSeconds($interval)
            ? self::STATUS_ONLINE
            : self::STATUS_OFFLINE;
    }
}
