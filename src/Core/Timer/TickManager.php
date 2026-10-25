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

namespace Swoolefy\Core\Timer;

use Swoole\Timer;
use Swoolefy\Core\Swfy;
use Swoole\Timer\Iterator;
use Swoolefy\Core\Table\TableManager;

class TickManager
{

    use \Swoolefy\Core\SingletonTrait;

    /**
     * tickTimer
     * @param int $timeIntervalMs
     * @param \Closure|array $func
     * @param array $params
     * @return int
     */
    public static function tickTimer(int $timeIntervalMs, \Closure|array $func, array $params = [])
    {
        return Tick::tickTimer($timeIntervalMs, $func, $params);
    }

    /**
     * afterTimer
     * @param int $timeIntervalMs
     * @param \Closure|array $func
     * @param mixed $params
     * @return int
     */
    public static function afterTimer(int $timeIntervalMs, \Closure|array $func, array $params = [])
    {
        return Tick::afterTimer($timeIntervalMs, $func, $params);
    }

    /**
     * clearTimer
     * @param int $timerId
     * @return bool
     */
    public static function clearTimer(int $timerId)
    {
        return Tick::delTicker($timerId);
    }

    /**
     * 合并各进程的循环定时器快照。key 是 pid:timer_id。已退出进程的行会删掉。
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getTickTasks(): array
    {
        return self::readTaskRows('table_ticker', 'tick_tasks');
    }

    /**
     * 合并各进程的一次性定时器快照。key 是 pid:timer_id。已退出进程的行会删掉。
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getAfterTasks(): array
    {
        return self::readTaskRows('table_after', 'after_tasks');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function readTaskRows(string $table, string $field): array
    {
        if (!self::taskTableEnabled() || !TableManager::isExistTable($table)) {
            return [];
        }

        $merged = [];
        $dead = [];
        foreach (TableManager::getTable($table) as $key => $row) {
            $payload = json_decode((string) ($row[$field] ?? ''), true);
            if (!is_array($payload)) {
                continue;
            }
            $pid = (int) ($payload['pid'] ?? $key);
            if (!self::pidAlive($pid)) {
                $dead[] = (string) $key;
                continue;
            }
            $tasks = $payload['tasks'] ?? [];
            if (!is_array($tasks)) {
                continue;
            }
            foreach ($tasks as $tid => $task) {
                if (!is_array($task)) {
                    continue;
                }
                $task['pid'] = $pid;
                $task['worker_id'] = $payload['worker_id'] ?? -1;
                if (!empty($payload['truncated'])) {
                    $task['snapshot_truncated'] = true;
                }
                $merged[$pid . ':' . $tid] = $task;
            }
        }
        foreach ($dead as $key) {
            TableManager::del($table, $key);
        }

        return $merged;
    }

    private static function taskTableEnabled(): bool
    {
        $conf = Swfy::getConf();
        if (!isset($conf['enable_table_tick_task'])) {
            return false;
        }

        return filter_var($conf['enable_table_tick_task'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
    }

    /**
     * ESRCH 为 3。EPERM 表示进程还在，只是当前用户不能发信号。
     */
    private static function pidAlive(int $pid): bool
    {
        if ($pid <= 0 || !function_exists('posix_kill')) {
            return true;
        }
        if (posix_kill($pid, 0)) {
            return true;
        }
        if (!function_exists('posix_errno')) {
            return true;
        }

        return posix_errno() !== 3;
    }

    /**
     * @param int $timerId
     * @return array
     */
    public static function timerInfo(int $timerId): ?array
    {
        return Timer::info($timerId);
    }

    /**
     * @return \Swoole\timer\Iterator
     */
    public static function timerList(): Iterator
    {
        return Timer::list();
    }

    /**
     * @return array
     */
    public static function timerStatus(): array
    {
        return Timer::stats();
    }

    /**
     * __callStatic
     * @param string $name
     * @param array $args
     * @return mixed
     */
    public static function __callStatic(string $name, array $args)
    {
        return false;
    }

}