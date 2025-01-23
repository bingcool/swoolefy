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
use Swoolefy\Worker\Dto\CronUrlTaskMetaDto;

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
        try {
            parent::run();
            $this->runCronTask();
        }catch (\Throwable $throwable) {
            $context = [
                'file' => $throwable->getFile(),
                'line' => $throwable->getLine(),
                'message' => $throwable->getMessage(),
                'code' => $throwable->getCode(),
                "reboot_count" => $this->getRebootCount(),
                'trace' => $throwable->getTraceAsString(),
            ];
            parent::onHandleException($throwable, $context);
            sleep(2);
            $this->reboot();
        }
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
                    $scheduleUrlTask = CronUrlTaskMetaDto::load($task);
                    $isNewAddFlag = $this->isNewAddTask($scheduleUrlTask->cron_name);
                    if ($isNewAddFlag) {
                        CrontabManager::getInstance()->addRule($task['cron_name'], $task['cron_expression'], function ($expression, $cron_name) use($scheduleUrlTask) {
                            if (is_array($scheduleUrlTask->before_callback) && count($scheduleUrlTask->before_callback) == 2) {
                                list($class, $action) = $scheduleUrlTask->before_callback;
                                (new $class)->{$action}($scheduleUrlTask);
                            }else if ($scheduleUrlTask->before_callback instanceof \Closure) {
                                $res = call_user_func($scheduleUrlTask->before_callback, $scheduleUrlTask);
                                if ($res === false) {
                                    $this->fmtWriteNote("cron_name=$cron_name task meta of before_callback return false, stop cron task");
                                    return false;
                                }
                            }

                            $httpClient = new CurlHttpClient();
                            $httpClient->setOptionArray($scheduleUrlTask->options ?? []);
                            $httpClient->setHeaderArray($scheduleUrlTask->headers ?? []);
                            $method = strtolower($scheduleUrlTask->method);
                            $rawResponse = $httpClient->{$method}(
                                $scheduleUrlTask->url,
                                $scheduleUrlTask->params ?? [],
                                $scheduleUrlTask->connect_time_out ?? 30,
                                $scheduleUrlTask->request_time_out ?? 60,
                            );

                            if (is_array($scheduleUrlTask->response_callback) && count($scheduleUrlTask->response_callback) == 2) {
                                list($class, $action) = $scheduleUrlTask->response_callback;
                                (new $class)->{$action}($rawResponse, $scheduleUrlTask);
                            }else if ($scheduleUrlTask->response_callback instanceof \Closure) {
                                call_user_func($scheduleUrlTask->response_callback, $rawResponse, $scheduleUrlTask);
                            }


                            if (is_array($scheduleUrlTask->after_callback) && count($scheduleUrlTask->after_callback) == 2) {
                                list($class, $action) = $scheduleUrlTask->after_callback;
                                (new $class)->{$action}($scheduleUrlTask);
                            }else if ($scheduleUrlTask->after_callback instanceof \Closure) {
                                call_user_func($scheduleUrlTask->after_callback, $scheduleUrlTask);
                            }
                        });
                    }
                }catch (\Throwable $throwable) {
                    $this->onHandleException($throwable, $task);
                }
            }
        }

        // 解除已暂停的定时任务
        $this->unregisterCronTask($taskList);
    }
}