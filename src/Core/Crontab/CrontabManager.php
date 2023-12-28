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
use Swoolefy\Core\Application;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Coroutine\Context;
use Swoolefy\Exception\CronException;

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
     * @param callable $callback
     */
    public function addRule(string $cronName, $expression, $func, \Closure $callback = null)
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

        $this->cronTasks[$cronNameKey] = [$expression, $func];

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

        $arrayCopy = Context::getContext()->getArrayCopy();
        if(is_numeric($expression)) {
            \Swoole\Timer::tick($expression * 1000, function ($timerId, $expression) use ($func, $cronName, $class, $arrayCopy, $callback) {
                foreach ($arrayCopy as $key=>$value) {
                    Context::set($key, $value);
                }
                goApp(function () use ($expression, $func, $cronName, $class, $callback) {
                    try {
                        if ($func instanceof \Closure) {
                            $cronControllerInstance = $this->buildCronControllerInstance();
                            call_user_func($func, $expression, $cronName);
                        }else if(is_array($func)) {
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
                        if (isset($cronControllerInstance)) {
                            /**
                             * @var AbstractCronController $cronControllerInstance
                            */
                            Application::removeApp($cronControllerInstance->getCid());
                        }

                        if (is_callable($callback)) {
                            call_user_func($callback);
                        }
                    }
                });
            }, $expression);

        }else {
            if (is_array($func)) {
                \Swoole\Timer::tick(2000, function ($timerId, $expression) use ($class, $cronName, $arrayCopy, $callback) {
                    foreach ($arrayCopy as $key=>$value) {
                        Context::set($key, $value);
                    }
                    goApp(function () use ($timerId, $expression, $class, $cronName, $callback) {
                        try {
                            /**
                             * @var AbstractCronController $cronControllerInstance
                             */
                            $cronControllerInstance = new $class;
                            $cronControllerInstance->runCron($cronName, $expression, null);
                        } catch (\Throwable $throwable) {
                           throw $throwable;
                        } finally {
                            Application::removeApp($cronControllerInstance->coroutineId);
                            if (is_callable($callback)) {
                                call_user_func($callback);
                            }
                        }
                    });
                }, $expression);
            } else {
                \Swoole\Timer::tick(2000, function ($timerId, $expression) use ($func, $cronName, $arrayCopy, $callback) {
                    foreach ($arrayCopy as $key=>$value) {
                        Context::set($key, $value);
                    }
                    goApp(function () use($timerId, $expression, $func, $cronName, $callback) {
                        try {
                            $cronControllerInstance = $this->buildCronControllerInstance();
                            $cronControllerInstance->runCron($cronName, $expression, $func);
                        } catch (\Throwable $throwable) {
                            throw $throwable;
                        } finally {
                            Application::removeApp($cronControllerInstance->coroutineId);
                            if (is_callable($callback)) {
                                call_user_func($callback);
                            }
                        }
                    });

                }, $expression);
            }
        }
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
            public function doCronTask($cron, string $cronName)
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

}