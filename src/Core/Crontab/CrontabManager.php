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

namespace Swoolefy\Core\Crontab;

use Cron\CronExpression;
use Swoolefy\Core\Coroutine\Context;
use Swoolefy\Core\Timer\Tick;
use Swoolefy\Exception\CronException;
use Swoolefy\Util\Helper;

class CrontabManager
{

    use \Swoolefy\Core\SingletonTrait;

    /**
     * @var array
     */
    protected $cronTasks = [];

    /**
     * @param string $cronName
     * @param string|float $expression
     * @param callable|array $func
     * @param callable $callPreFn
     * @param callable $callback
     */
    public function addRule(string $cronName, string|float $expression, mixed $func, ?\Closure $callPreFn = null, ?\Closure $callback = null, array $extend = [])
    {
        if (!class_exists('Cron\\CronExpression')) {
            throw new CronException("If you want to use crontab, you need to install 'composer require dragonmantank/cron-expression' ");
        }

        if (!is_numeric($expression) && !CronExpression::isValidExpression($expression)) {
            throw new CronException("Crontab expression format is wrong, please check it");
        }

        $cronNameKey = md5($cronName);

        if (isset($this->cronTasks[$cronNameKey])) {
            throw new CronException(sprintf("Cron name=s% had been setting, you can not set same name again",
                $cronName)
            );
        }

        $this->cronTasks[$cronNameKey] = ['expression' => $expression, 'func' => $func, 'timer_id' => 0, 'cron_name' => $cronName, 'extend' => $extend];

        $class = '';
        if(is_array($func)) {
            list($class,) = $func;
            if (!is_subclass_of($class, '\\Swoolefy\\Core\\Crontab\\AbstractCronController')) {
                throw new CronException(sprintf(
                    "s%::s% Params of func about Crontab Handle Controller need to extend Swoolefy\\Core\\Crontab\\AbstractCronController",
                    __CLASS__,
                    __FUNCTION__
                ));
            }
        }

        // 注册时不捕获请求 Context；每次触发写入系统字段，业务值须显式传参
        if(is_numeric($expression)) {
            /**
                注册时不捕获请求 Context，避免长期 Timer 故意不带携带用户身份。Tick/After 是进程级长期定时器，不是请求内的子协程。
                若注册时捕获当前请求 Context，`user` / `tenant` 会被冻住，之后每次触发都带着那次请求的身份，造成跨请求串权。
                当前行为：
                - 注册：不 `snapshot()` 请求 Context
                - 触发：`goApp` 新建干净协程，只写 `_sys_cron_*` 与 `x-trace-id` 系统字段
                - 业务数据：用注册时的 `$params` 显式传入
                这和 `goApp` 不同：`goApp` 跟请求同生共灭，适合短期并发；Cron 会跨大量请求存活，不能背请求身份
             */
            $timerId = \Swoole\Timer::tick($expression * 1000, function ($timerId, $expression) use ($func, $cronName, $class, $callPreFn, $callback) {
                goApp(function () use ($expression, $func, $cronName, $class, $callPreFn, $callback) {
                    // 每次触发仅写入系统字段（含独立 trace id，便于日志排查）
                    Context::set(Tick::CTX_SYS_CRON_NAME, $cronName);
                    Context::set(Tick::CTX_SYS_CRON_TRIGGER_TIME, time());
                    Context::set(Tick::CTX_X_TRACE_ID, Helper::UUid());
                    $isNext = true;
                    try {
                        if (is_callable($callPreFn)) {
                            $isNext = call_user_func($callPreFn);
                        }

                        // return false to over function
                        if (isset($isNext) && $isNext === false) {
                            return false;
                        }

                        $isNext = true;

                        if ($func instanceof \Closure) {
                            call_user_func($func, $expression, $cronName);
                        } else if (is_array($func)) {
                            /**
                             * @var AbstractCronController $cronControllerInstance
                             */
                            $cronControllerInstance = new $class;
                            $cronControllerInstance->doCronTask($expression, $cronName);

                            if (!$cronControllerInstance->isDefer()) {
                                $cronControllerInstance->end();
                            }
                        }
                    } catch (\Throwable $throwable) {
                       throw $throwable;
                    } finally {
                        if (is_callable($callback) && $isNext) {
                            call_user_func($callback);
                        }
                    }
                });
            }, $expression);

        }else {
            if (is_array($func)) {
                $timerId = \Swoole\Timer::tick(2000, function ($timerId, $expression) use ($class, $cronName, $callPreFn, $callback) {
                    goApp(function () use ($timerId, $expression, $class, $cronName, $callPreFn, $callback) {
                        // 每次触发仅写入系统字段（含独立 trace id，便于日志排查）
                        Context::set(Tick::CTX_SYS_CRON_NAME, $cronName);
                        Context::set(Tick::CTX_SYS_CRON_TRIGGER_TIME, time());
                        Context::set(Tick::CTX_X_TRACE_ID, Helper::UUid());
                        $isNext = true;
                        try {
                            if (is_callable($callPreFn)) {
                                $isNext = call_user_func($callPreFn);
                            }

                            // return false to over function
                            if (isset($isNext) && $isNext === false) {
                                return false;
                            }

                            $isNext = true;

                            /**
                             * @var AbstractCronController $cronControllerInstance
                             */
                            $cronControllerInstance = new $class;
                            $cronControllerInstance->runCron($cronName, $expression, null);
                        } catch (\Throwable $throwable) {
                           throw $throwable;
                        } finally {
                            if (is_callable($callback) && $isNext) {
                                call_user_func($callback);
                            }
                        }
                    });
                }, $expression);
            } else {
                $timerId = \Swoole\Timer::tick(2000, function ($timerId, $expression) use ($func, $cronName, $callPreFn, $callback) {
                    goApp(function () use($timerId, $expression, $func, $cronName, $callPreFn, $callback) {
                        // 每次触发仅写入系统字段（含独立 trace id，便于日志排查）
                        Context::set(Tick::CTX_SYS_CRON_NAME, $cronName);
                        Context::set(Tick::CTX_SYS_CRON_TRIGGER_TIME, time());
                        Context::set(Tick::CTX_X_TRACE_ID, Helper::UUid());
                        $isNext = true;
                        try {
                            if (is_callable($callPreFn)) {
                                $isNext = call_user_func($callPreFn);
                            }

                            // return false to over function
                            if (isset($isNext) && $isNext === false) {
                                return false;
                            }

                            $isNext = true;

                            $cronControllerInstance = $this->buildCronControllerInstance();
                            $cronControllerInstance->runCron($cronName, $expression, $func);
                        } catch (\Throwable $throwable) {
                            throw $throwable;
                        } finally {
                            if (is_callable($callback) && $isNext) {
                                call_user_func($callback);
                            }
                        }
                    });

                }, $expression);
            }
        }

        $this->cronTasks[$cronNameKey]['timer_id'] = $timerId ?? 0;

        unset($cronNameKey);
    }

    /**
     * @return AbstractCronController
     */
    protected function buildCronControllerInstance(): AbstractCronController
    {
        return new class extends AbstractCronController {
            /**
             * @inheritDoc
             */
            public function doCronTask(CronExpression|float $cron, string $cronName)
            {
            }
        };
    }

    /**
     * @param string|null $cronName
     * @return array|null
     */
    public function getCronTaskByName(?string $cronName = null)
    {
        if ($cronName) {
            $cronNameKey = md5($cronName);
            if (isset($this->cronTasks[$cronNameKey])) {
                return $this->cronTasks[$cronNameKey];
            }
            return null;
        }
        return $this->cronTasks;
    }

    /**
     * @param string|null $cronName
     * @return array|null
     */
    public function getRunCronTaskList()
    {
        return $this->cronTasks;
    }

    /**
     * @param string $cronName
     * @return void
     */
    public function removeCronTaskByName(string $cronName)
    {
        $cronNameKey = md5($cronName);
        if (isset($this->cronTasks[$cronNameKey])) {
            $cronTask = $this->cronTasks[$cronNameKey];
            $timerId  = $cronTask['timer_id'];
            if (\Swoole\Timer::exists($timerId)) {
                \Swoole\Timer::clear($timerId);
            }
            unset($this->cronTasks[$cronNameKey]);
        }
    }

}
