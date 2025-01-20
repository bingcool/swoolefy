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

use Swoolefy\Core\Crontab\CrontabManager;
use Common\Library\HttpClient\CurlHttpClient;

class CronUrlProcess extends CronProcess
{

    /**
     * onInit
     * @return void
     */
    public function onInit()
    {
        parent::onInit();
    }

    /**
     * run
     */
    public function run()
    {
        parent::run();
        $this->runCronTask();
    }

    /**
     * @param array $taskList
     * @return void
     */
    protected function registerCronTask(array $taskList)
    {
        if(!empty($taskList)) {
            foreach($taskList as $task) {
                try {
                    $isNewAddFlag = $this->isNewAddTask($task['cron_name']);
                    if ($isNewAddFlag) {
                        CrontabManager::getInstance()->addRule($task['cron_name'], $task['cron_expression'], function ($expression, $cron_name) use($task) {
                            $httpClient = new CurlHttpClient();
                            $httpClient->setOptionArray($task['options'] ?? []);
                            $httpClient->setHeaderArray($task['headers'] ?? []);
                            $method = strtolower($task['method']);
                            $rawResponse = $httpClient->{$method}(
                                $task['url'],
                                $task['params'] ?? [],
                                $task['connect_time_out'] ?? 5,
                                $task['curl_time_out'] ?? 10,
                            );

                            if (isset($task['callback']) && is_array($task['callback']) && count($task['callback']) == 2) {
                                list($class, $action) = $task['callback'];
                                (new $class)->{$action}($rawResponse, $task);
                            } else if (isset($task['callback']) && $task['callback'] instanceof \Closure) {
                                call_user_func($task['callback'], $rawResponse, $task);
                            }
                        });
                    }
                }catch (\Throwable $throwable) {
                    $this->onHandleException($throwable, $task);
                }
            }

            // 解除已暂停的定时任务
            $this->unregisterCronTask($taskList);
        }
    }
}