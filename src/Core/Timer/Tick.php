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

namespace Swoolefy\Core\Timer;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Coroutine\Context;
use Swoolefy\Core\Table\TableManager;
use Swoolefy\Exception\TimerException;
use Swoolefy\Util\Helper;

class Tick
{
    /**
     * Context key: coroutine-level trace id written on Tick/Cron trigger.
     */
    public const CTX_X_TRACE_ID = 'x-trace-id';

    /**
     * Context key: cron task name written on Cron trigger.
     */
    public const CTX_SYS_CRON_NAME = '_sys_cron_name';

    /**
     * Context key: cron trigger unix timestamp.
     */
    public const CTX_SYS_CRON_TRIGGER_TIME = '_sys_cron_trigger_time';

    /**
     * Context key: tick timer id written on Tick trigger.
     */
    public const CTX_SYS_TICK_TIMER_ID = '_sys_tick_timer_id';

    /**
     * Context key: tick/after trigger unix timestamp.
     */
    public const CTX_SYS_TICK_TRIGGER_TIME = '_sys_tick_trigger_time';

    /**
     * Tick 触发时 x-trace-id 前缀，便于与 After/Cron 区分。
     */
    public const TRACE_ID_PREFIX_TICKTIMER = 'ticktimer_';

    /**
     * After 触发时 x-trace-id 前缀，便于与 Tick/Cron 区分。
     */
    public const TRACE_ID_PREFIX_AFTERTIMER = 'aftertimer_';

    /**
     * $_tick_tasks
     * @var array
     */
    protected static $_tick_tasks = [];

    /**
     * $_after_tasks
     * @var array
     */
    protected static $_after_tasks = [];

    /**
     * $_tasks_instances 任务对象类
     * @var array
     */
    protected static $_tasks_instances = [];

    /**
     * tickTimer
     * @param int $timeIntervalMs
     * @param \Closure|array $func
     * @param array $params
     * @return int
     */
    public static function tickTimer(int $timeIntervalMs, \Closure|array $func, array $params = [])
    {
        if ($timeIntervalMs <= 0) {
            throw new TimerException(get_called_class() . "::tickTimer() the first params 'time_interval_ms' is requested more than 0 ms");
        }

        $timerId = self::tick($timeIntervalMs, $func, $params);

        return $timerId;
    }

    /**
     * tick
     * @param int $timeIntervalMs
     * @param \Closure|array $func
     * @param array $params
     * @return mixed
     */
    protected static function tick(int $timeIntervalMs, \Closure|array $func, array $params = [])
    {
        /**
            注册时不捕获请求 Context，避免长期 Timer 故意不带携带用户身份。Tick/After 是进程级长期定时器，不是请求内的子协程。
            若注册时捕获当前请求 Context，`user` / `tenant` 会被冻住，之后每次触发都带着那次请求的身份，造成跨请求串权。
            当前行为：
            - 注册：不 `snapshot()` 请求 Context
            - 触发：`goApp` 新建干净协程，只写 `_sys_tick_*` 与 `x-trace-id` 系统字段
            - 业务数据：用注册时的 `$params` 显式传入
            这和 `goApp` 不同：`goApp` 跟请求同生共灭，适合短期并发；Tick 会跨大量请求存活，不能背请求身份
        */
        $tid = \Swoole\Timer::tick($timeIntervalMs, function ($timerId, $params) use ($func) {
            goApp(function() use($timerId, $params, $func) {
                // 每次触发仅写入系统字段（含独立 trace id，便于日志排查）
                Context::set(self::CTX_SYS_TICK_TIMER_ID, $timerId);
                Context::set(self::CTX_SYS_TICK_TRIGGER_TIME, time());
                Context::set(self::CTX_X_TRACE_ID, self::TRACE_ID_PREFIX_TICKTIMER . Helper::UUid());
                try {
                    if (is_array($func)) {
                        list($class, $action) = $func;
                        $tickTaskInstance = new $class;
                        $tickTaskInstance->{$action}(...[$params, $timerId]);
                    } else if ($func instanceof \Closure) {
                        call_user_func($func, $params, $timerId);
                    }
                    // call after action
                    if (isset($tickTaskInstance)) {
                        $tickTaskInstance->afterHandle();
                    }
                } catch (\Throwable $throwable) {
                    throw $throwable;
                } finally {
                    if (isset($tickTaskInstance)) {
                        if ($tickTaskInstance->isDefer() === false) {
                            $tickTaskInstance->end();
                        }
                        unset($tickTaskInstance);
                    }
                }
                unset($class, $action, $func);
            });
        }, $params);

        if ($tid) {
            self::$_tick_tasks[$tid] = [
                'callback'      => $func,
                'params'        => $params,
                'time_interval' => $timeIntervalMs,
                'timer_id'      => $tid,
                'start_time'    => date('Y-m-d H:i:s', strtotime('now'))
            ];

            // 内存表只存本进程的可序列化快照，闭包仍留在 $_tick_tasks
            self::publishTickTasks();

        }

        return $tid;
    }

    /**
     * delTicker
     * @param int $timerId
     * @return bool
     */
    public static function delTicker(int $timerId): bool
    {
        if (!\Swoole\Timer::exists($timerId)) {
            return true;
        }

        $result = \Swoole\Timer::clear($timerId);

        if ($result) {
            foreach (self::$_tick_tasks as $tid => $value) {
                if ($tid == $timerId) {
                    unset(self::$_tick_tasks[$timerId], self::$_tasks_instances[$timerId]);
                }
            }

            // 删除后重写本进程这一行，避免表里还留着已清掉的定时器
            self::publishTickTasks();
            return true;
        }

        return false;
    }

    /**
     * afterTimer
     * @param int $timeIntervalMs
     * @param \Closure|array $func
     * @param array $params
     * @return int
     * @throws mixed
     */
    public static function afterTimer(int $timeIntervalMs, \Closure|array $func, array $params = [])
    {
        if ($timeIntervalMs <= 0) {
            throw new TimerException(get_called_class() . "::afterTimer() the first params 'time_interval_ms' is requested more then 0 ms");
        }

        $timerId = self::after($timeIntervalMs, $func, $params);
        return $timerId;
    }

    /**
     * after
     * @param int $timeIntervalMs
     * @param \Closure|array $func
     * @param array $params
     * @return bool|mixed
     */
    protected static function after(int $timeIntervalMs, \Closure|array $func, array $params = [])
    {
        // 注册时不捕获请求 Context，避免 after Timer 隐式复制用户身份
        $timerId = \Swoole\Timer::after($timeIntervalMs, function ($params) use ($func) {
            goApp(function () use($params, $func) {
                // 每次触发仅写入系统字段（含独立 trace id，便于日志排查）
                Context::set(self::CTX_SYS_TICK_TRIGGER_TIME, time());
                Context::set(self::CTX_X_TRACE_ID, self::TRACE_ID_PREFIX_AFTERTIMER . Helper::UUid());
                try {
                    $timer_id = null;
                    if (is_array($func)) {
                        list($class, $action) = $func;
                        $tickTaskInstance = new $class;
                        $tickTaskInstance->{$action}(...[$params, $timer_id]);
                    } else if ($func instanceof \Closure) {
                        call_user_func($func, $params, $timer_id);
                    }
                    // call after action
                    if (isset($tickTaskInstance)) {
                        $tickTaskInstance->afterHandle();
                    }
                } catch (\Throwable $throwable) {
                    throw $throwable;
                } finally {
                    if (isset($tickTaskInstance)) {
                        if ($tickTaskInstance->isDefer() === false) {
                            $tickTaskInstance->end();
                        }
                        unset($tickTaskInstance);
                    }
                }

                self::updateRunAfterTick();
                unset($class, $action, $func);
            });
        }, $params);

        if ($timerId) {
            self::$_after_tasks[$timerId] = [
                'callback'      => $func,
                'params'        => $params,
                'time_interval' => $timeIntervalMs,
                'timer_id'      => $timerId,
                'start_time'    => date('Y-m-d H:i:s', strtotime('now'))
            ];

            // 内存表只存本进程的可序列化快照，闭包仍留在 $_after_tasks
            self::publishAfterTasks();
        }

        return $timerId;
    }

    /**
     * updateRunAfterTick
     * @return void
     */
    protected static function updateRunAfterTick()
    {
        if (self::$_after_tasks) {
            $now = strtotime('now') * 1000 + 1000;

            foreach (self::$_after_tasks as $key => $value) {
                $end_time = $value['time_interval'] + strtotime($value['start_time']) * 1000;
                if ($now >= $end_time) {
                    unset(self::$_after_tasks[$key]);
                }
            }

            // 到期项从本进程列表去掉后，同步删掉内存表里的对应快照
            self::publishAfterTasks();
        }
    }

    /**
     * table_ticker / table_after 的字符串列宽是 8096 字节。
     * 快照停在 8000，给 Swoole 的结尾 NUL 留出余量，避免 set 时被截成非法 JSON。
     */
    private const TASK_TABLE_JSON_LIMIT = 8000;

    /**
     * 本进程写内存表失败后只记一次日志。
     * 定时器注册本身不中断，避免每次 tick / after 都刷同一条错误。
     */
    private static bool $taskTableWriteFailed = false;

    /**
     * 把本进程的循环定时器快照写入 table_ticker。
     * 行键是 pid，Worker 之间不会互相覆盖。真正的闭包仍在 $_tick_tasks。
     *
     * @return void
     */
    private static function publishTickTasks(): void
    {
        self::publishTaskTable('table_ticker', 'tick_tasks', self::$_tick_tasks);
    }

    /**
     * 把本进程的一次性定时器快照写入 table_after。
     * 行键是 pid。真正的闭包仍在 $_after_tasks。
     *
     * @return void
     */
    private static function publishAfterTasks(): void
    {
        self::publishTaskTable('table_after', 'after_tasks', self::$_after_tasks);
    }

    /**
     * 按 pid 写入一行定时器快照。enable_table_tick_task 未打开时直接返回。
     *
     * 写入内容不含闭包。单行超过 TASK_TABLE_JSON_LIMIT 时截断任务并带 truncated。
     * 列宽不够、读回与写入不一致时，改写一条空的截断快照；仍失败则删除该行。
     * 失败只 error_log 一次，不把异常抛回定时器注册。
     *
     * @param string $table table_ticker 或 table_after
     * @param string $field 列名，tick_tasks 或 after_tasks
     * @param array<int|string, mixed> $tasks 进程内的原始任务，含 callback
     * @return void
     */
    private static function publishTaskTable(string $table, string $field, array $tasks): void
    {
        if (!self::taskTableEnabled()) {
            return;
        }

        $key = (string) getmypid();
        $json = self::encodeTaskSnapshot($tasks);
        if (self::writeTaskRow($table, $key, $field, $json)) {
            return;
        }

        // 当前 Swoole 在超长时仍返回 true，并把字符串截成非法 JSON。读回不一致就改写短快照。
        $fallback = self::packSnapshot([], true);
        if ($fallback !== $json && self::writeTaskRow($table, $key, $field, $fallback)) {
            return;
        }

        TableManager::del($table, $key);
        if (self::$taskTableWriteFailed) {
            return;
        }

        self::$taskTableWriteFailed = true;
        error_log(sprintf('timer table %s set failed, pid=%d, bytes=%d', $table, getmypid(), strlen($json)));
    }

    /**
     * 写入一行并读回校验。
     * Swoole\Table::set 在字符串超过列宽时可能返回 true，同时把值截断，所以不能只看返回值。
     *
     * @param string $table 内存表名
     * @param string $key 行键，当前进程 pid
     * @param string $field 字符串列名
     * @param string $json 准备写入的快照，单位字节，须能完整放进列宽
     * @return bool 读回内容与 $json 完全一致时为 true
     */
    private static function writeTaskRow(string $table, string $key, string $field, string $json): bool
    {
        if (!TableManager::set($table, $key, [$field => $json])) {
            return false;
        }

        return TableManager::get($table, $key, $field) === $json;
    }

    /**
     * 是否把定时器快照同步到内存表。
     * 只认 enable_table_tick_task 的布尔真值；未配置或 "false" 都不写。
     *
     * @return bool
     */
    private static function taskTableEnabled(): bool
    {
        $conf = Swfy::getConf();
        if (!isset($conf['enable_table_tick_task'])) {
            return false;
        }

        return filter_var($conf['enable_table_tick_task'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
    }

    /**
     * 把进程内任务编成一行 JSON。
     * 按注册顺序逐个加入，下一条会让整行超过 TASK_TABLE_JSON_LIMIT 时停止，并标记 truncated。
     * 连第一条都放不下时，退成空任务列表，避免写出超长字符串。
     *
     * @param array<int|string, mixed> $tasks 进程内原始任务
     * @return string 不超过 TASK_TABLE_JSON_LIMIT 字节的 JSON
     */
    private static function encodeTaskSnapshot(array $tasks): string
    {
        $rows = [];
        $truncated = false;
        foreach ($tasks as $id => $task) {
            if (!is_array($task)) {
                continue;
            }
            $next = $rows;
            $next[(string) $id] = self::timerTaskRow($task);
            if (strlen(self::packSnapshot($next, false)) > self::TASK_TABLE_JSON_LIMIT) {
                $truncated = true;
                break;
            }
            $rows = $next;
        }

        $json = self::packSnapshot($rows, $truncated);
        if (strlen($json) > self::TASK_TABLE_JSON_LIMIT) {
            $json = self::packSnapshot([], true);
        }

        return $json;
    }

    /**
     * 组装跨进程可读的快照。tasks 用对象而不是空数组，避免 json 被编成 [] 后和「有任务」的对象形态混在一起。
     *
     * 字段：pid、worker_id、truncated、tasks。单位：pid / worker_id 为整数，truncated 表示本行是否被截断。
     *
     * @param array<string, array<string, mixed>> $rows 已去掉闭包的任务行，键是 timer_id
     * @param bool $truncated 为 true 时表示还有任务没写进这一行
     * @return string JSON。编码失败时返回一条空的截断快照，避免 json_encode 警告被转成异常
     */
    private static function packSnapshot(array $rows, bool $truncated): string
    {
        $json = json_encode([
            'pid' => getmypid(),
            'worker_id' => self::processWorkerId(),
            'truncated' => $truncated,
            'tasks' => $rows === [] ? new \stdClass() : $rows,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : '{"pid":0,"worker_id":-1,"truncated":true,"tasks":{}}';
    }

    /**
     * 从进程内任务抽出可放进内存表的字段。
     * callback 只保留名字。params 含对象或闭包时整段省掉，避免 json_encode 发出警告。
     *
     * @param array<string, mixed> $task $_tick_tasks / $_after_tasks 的一项
     * @return array<string, mixed> timer_id、time_interval（毫秒）、start_time、callback 名称、pid，以及可选的 params
     */
    private static function timerTaskRow(array $task): array
    {
        $row = [
            'timer_id' => $task['timer_id'] ?? null,
            'time_interval' => $task['time_interval'] ?? null,
            'start_time' => $task['start_time'] ?? null,
            'callback' => self::callbackName($task['callback'] ?? null),
            'pid' => getmypid(),
        ];
        if (isset($task['params']) && self::isJsonScalarTree($task['params'])) {
            $row['params'] = $task['params'];
        }

        return $row;
    }

    /**
     * 把回调记成跨进程可读的字符串。闭包没有稳定名字，统一记为 Closure。
     *
     * @param mixed $callback 进程内保存的 Closure、[class, method] 或函数名
     * @return string Closure、Class::method、函数名，或无法识别时的 callable
     */
    private static function callbackName(mixed $callback): string
    {
        if ($callback instanceof \Closure) {
            return 'Closure';
        }
        if (is_array($callback) && isset($callback[0], $callback[1]) && is_string($callback[1])) {
            $class = is_object($callback[0]) ? $callback[0]::class : (is_string($callback[0]) ? $callback[0] : 'callable');

            return $class . '::' . $callback[1];
        }
        if (is_string($callback)) {
            return $callback;
        }

        return 'callable';
    }

    /**
     * 判断值能否原样放进 json，且不会触发 json_encode 警告。
     * 只接受 null、标量和由它们组成的数组。对象、闭包、资源都视为不能写入。
     *
     * @param mixed $value 任务的 params
     * @return bool
     */
    private static function isJsonScalarTree(mixed $value): bool
    {
        if ($value === null || is_scalar($value)) {
            return true;
        }
        if (!is_array($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (!self::isJsonScalarTree($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 当前进程在 Swoole 里的 worker_id。自定义进程或 Server 尚未就绪时为 -1。
     *
     * @return int
     */
    private static function processWorkerId(): int
    {
        $server = Swfy::getServer();
        if (!is_object($server) || !isset($server->worker_id)) {
            return -1;
        }

        return (int) $server->worker_id;
    }

}