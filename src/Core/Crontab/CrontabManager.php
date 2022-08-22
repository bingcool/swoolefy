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

class CrontabManager
{

    use \Swoolefy\Core\SingletonTrait;

    /**
     * @var array
     */
    protected $cronTasks = [];

    /**
     * @param string $cronName
     * @param string $expression
     * @param callable|array $func
     * @throws Exception
     */
    public function addRule(string $cronName, string $expression, $func)
    {
        if (!class_exists('Cron\\CronExpression')) {
            throw new \Exception("If you want to use crontab, you need to install 'composer require dragonmantank/cron-expression' ");
        }

        if (!CronExpression::isValidExpression($expression)) {
            throw new \Exception("Crontab expression format is wrong, please check it");
        }

        $cronNameKey = md5($cronName);

        if (isset($this->cronTasks[$cronNameKey])) {
            throw new \Exception("Cron name=$cronName had been setting, you can not set same name again!");
        }

        $this->cronTasks[$cronNameKey] = [$expression, $func];

        if (is_array($func)) {
            list($class, $action) = $func;
            if (!is_subclass_of($class, '\\Swoolefy\\Core\\Crontab\\AbstractCronController')) {
                throw new \Exception(__CLASS__ ."::". __FUNCTION__ . " Params of func about Crontab Handle Controller need to extend Swoolefy\\Core\\Crontab\\AbstractCronController");
            }
            \Swoole\Timer::tick(1000, function ($timerId, $expression) use ($class, $action, $cronName) {
                try {
                    /**
                     * @var AbstractCronController $cronControllerInstance
                     */
                    $cronControllerInstance = new $class;
                    $cronControllerInstance->runCron($cronName, $expression, null);
                } catch (\Throwable $throwable) {
                    BaseServer::catchException($throwable);
                } finally {
                    Application::removeApp($cronControllerInstance->coroutine_id);
                }
            }, $expression);
        } else {
            \Swoole\Timer::tick(1000, function ($timerId, $expression) use ($func, $cronName) {
                try {
                    $cronControllerInstance = new class extends AbstractCronController {
                        /**
                         * @inheritDoc
                         */
                        public function doCronTask(CronExpression $cron, string $cron_name)
                        {
                        }
                    };

                    $cronControllerInstance->runCron($cronName, $expression, $func);

                } catch (\Throwable $throwable) {
                    BaseServer::catchException($throwable);
                } finally {
                    Application::removeApp($cronControllerInstance->coroutine_id);
                }
            }, $expression);
        }

        unset($cronNameKey);
    }

    /**
     * @param string|null $cron_name
     * @return array|null
     */
    public function getCronTaskByName(?string $cron_name = null)
    {
        if ($cron_name) {
            $cronNameKey = md5($cron_name);
            if (isset($this->cronTasks[$cronNameKey])) {
                return $this->cronTasks[$cronNameKey];
            }
            return null;
        }
        return $this->cronTasks;
    }

}