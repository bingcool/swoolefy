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
 * 计算任务下一合法执行点（unix 秒），供 HTTP Admin 列表展示。
 *
 * HTTP Admin 与 Cron Worker 进程隔离，读不到 Runtime 内存里的 nextRunAt。
 * 本类用与引擎相同的 ExpressionParser / calculateNextRunAt / TimeWindowFilter 推算：
 * - 只取严格晚于基准的下一合法点，不回填历史 misfire
 * - 禁用任务视为暂停，返回 null
 * - 非法表达式返回 null，不抛异常（不得拖垮整页列表）
 * - 若 cron_between / cron_skip 会使该点 SKIP，则继续往后找下一个允许触发的点
 *
 * 这不是 Worker 武装 Timer 的路径；Scheduler 仍按表达式点 arm，时间窗在 onTrigger 时 SKIP。
 *
 * @see ExpressionParser
 * @see TimeWindowFilter
 * @see CronScheduler::arm()
 */
final class CronNextRunAt
{
    /** 查找允许触发点时最多前进的调度 hop 数，防止 between/skip 把整天挡住导致死循环。 */
    private const MAX_HOPS = 20000;

    /** 最多向前看的秒数（8 天）。超时仍找不到允许点则返回 null。 */
    private const MAX_LOOKAHEAD_SECONDS = 8 * 86400;

    /**
     * 根据 cron_task 行（或 TaskDefinition::fromArray 可消费的数组）计算下次执行 unix 秒。
     *
     * @param array<string, mixed> $row 须含 expression；status 缺省视为启用
     * @param int|null $fromTimestamp 基准 unix 秒；空则 time()。只算严格晚于该点的下一格
     */
    public static function compute(array $row, ?int $fromTimestamp = null): ?int
    {
        $status = array_key_exists('status', $row)
            ? (int) $row['status']
            : TaskDefinition::STATUS_ENABLED;
        if ($status !== TaskDefinition::STATUS_ENABLED) {
            return null;
        }

        $from = $fromTimestamp ?? time();
        try {
            $definition = TaskDefinition::fromArray($row);
            $schedule = (new ExpressionParser())->parse($definition->expression, $definition->timezone);
        } catch (\Throwable) {
            // 非法表达式 / 缺身份：列表仍要返回其它行
            return null;
        }

        $filter = new TimeWindowFilter();
        $cursor = $from;
        $deadline = $from + self::MAX_LOOKAHEAD_SECONDS;

        for ($i = 0; $i < self::MAX_HOPS; ++$i) {
            $next = $schedule->calculateNextRunAt($cursor);
            if ($next <= $cursor) {
                return null;
            }
            if ($filter->evaluate($definition, $next)['allowed']) {
                return $next;
            }
            if ($next > $deadline) {
                return null;
            }
            $cursor = $next;
        }

        return null;
    }

    /**
     * unix 秒格式化为与 createdAt / lastHeartbeatAt 相同的 datetime 串；null 为空串。
     */
    public static function formatDatetime(?int $unix): string
    {
        if ($unix === null || $unix <= 0) {
            return '';
        }

        return date('Y-m-d H:i:s', $unix);
    }
}
