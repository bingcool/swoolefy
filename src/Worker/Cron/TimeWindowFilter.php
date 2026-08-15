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
 * cron_between / cron_skip 时间窗判断。
 *
 * 边界：不修改 expression、不改 Timer、不调用 Executor。
 * CronManager::onTrigger() 在 Guard 之前调用；不允许则 recordSkip，本轮结束。
 *
 * 判定：allowed = inside(cron_between) && !inside(cron_skip)
 * - cron_between 为空：视为全天允许
 * - cron_skip 为空：不额外排除
 * - 多窗之间为 OR：任一 between 命中即允许，任一 skip 命中即拒绝
 *
 * 时刻窗（HH:MM / HH:MM:SS）相对 $now 所在日期拼出当天时间戳，
 * 避免依赖系统“今天”与历史 ScheduleEvent::parseBetweenTime 的 date() 不一致。
 * 非时刻串（完整日期时间）走 strtotime()；解析失败的窗视为不命中。
 *
 * @see CronManager::onTrigger()
 */
final class TimeWindowFilter
{
    /**
     * 判断 $now 是否落在允许窗内。reason 仅为 cron_between / cron_skip，供日志。
     *
     * @return array{allowed:bool,reason:string}
     */
    public function evaluate(TaskDefinition $definition, int $now): array
    {
        if (!$this->insideBetween($definition->cronBetween, $now)) {
            return ['allowed' => false, 'reason' => 'cron_between'];
        }
        if ($this->insideSkip($definition->cronSkip, $now)) {
            return ['allowed' => false, 'reason' => 'cron_skip'];
        }

        return ['allowed' => true, 'reason' => ''];
    }

    /**
     * 空窗 = 不限制；否则任一窗命中即 true。
     *
     * @param list<array<int, mixed>> $windows
     */
    private function insideBetween(array $windows, int $now): bool
    {
        if ($windows === []) {
            return true;
        }
        foreach ($windows as $window) {
            if ($this->inWindow($window, $now)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 空窗 = 不跳过；否则任一窗命中即 true（本轮 SKIP）。
     *
     * @param list<array<int, mixed>> $windows
     */
    private function insideSkip(array $windows, int $now): bool
    {
        foreach ($windows as $window) {
            if ($this->inWindow($window, $now)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 单窗 [start, end] 闭区间。元素不足 2 个或无法解析则视为不命中。
     *
     * @param mixed $window
     */
    private function inWindow(mixed $window, int $now): bool
    {
        if (!is_array($window) || count($window) < 2) {
            return false;
        }
        $range = $this->resolveRange((string) $window[0], (string) $window[1], $now);
        if ($range === null) {
            return false;
        }

        return $range[0] <= $now && $now <= $range[1];
    }

    /**
     * 时刻窗锚定到 $now 的 Y-m-d；跨日窗（如 22:00-02:00）按字面时间戳比较，不自动拆日。
     *
     * @return array{0:int,1:int}|null
     */
    private function resolveRange(string $start, string $end, int $now): ?array
    {
        if ($this->isTimeOfDay($start) && $this->isTimeOfDay($end)) {
            $date = date('Y-m-d', $now);
            $startTs = strtotime($date . ' ' . $start);
            $endTs = strtotime($date . ' ' . $end);
        } else {
            $startTs = strtotime($start);
            $endTs = strtotime($end);
        }
        if ($startTs === false || $endTs === false) {
            return null;
        }

        return [$startTs, $endTs];
    }

    /**
     * HH:MM 或 HH:MM:SS。
     */
    private function isTimeOfDay(string $value): bool
    {
        return preg_match('/^([01]?\d|2[0-3]):[0-5]?\d(:[0-5]?\d)?$/', $value) === 1;
    }
}
