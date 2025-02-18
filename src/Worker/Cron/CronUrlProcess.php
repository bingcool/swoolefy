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
use Swoolefy\Core\Log\LogManager;
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
                    $isNewAddFlag = $this->isNewAddTask($task['cron_name']);
                    if ($isNewAddFlag) {
                        CrontabManager::getInstance()->addRule($task['cron_name'], $task['cron_expression'], function ($expression, $cron_name) use($task) {
                            $scheduleUrlTask = CronUrlTaskMetaDto::load($task);
                            $logger = LogManager::getInstance()->getLogger(LogManager::CRON_URL_LOG);
                            try {
                                $logger->addInfo("【{$cron_name}】开始处理远程请求url定时任务，url={$scheduleUrlTask->url}");
                                if (is_array($scheduleUrlTask->before_callback) && count($scheduleUrlTask->before_callback) == 2) {
                                    list($class, $action) = $scheduleUrlTask->before_callback;
                                    (new $class)->{$action}($scheduleUrlTask);
                                }else if ($scheduleUrlTask->before_callback instanceof \Closure) {
                                    $res = call_user_func($scheduleUrlTask->before_callback, $scheduleUrlTask);
                                    if ($res === false) {
                                        $logger->addInfo("【{$cron_name}】远程请求url定时任务before_callback函数返回false，暂停继续往下执行，url={$scheduleUrlTask->url}");
                                        fmtPrintNote("cron_name=$cron_name 远程请求url定时任务before_callback函数返回false，暂停继续往下执行");
                                        return false;
                                    }
                                }

                                $logger->addInfo("【{$cron_name}】httpCurl-发起远程请求url，url={$scheduleUrlTask->url}");
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

                                $responseLogMsg = "【{$cron_name}】response_callback-远程请求执行响应逻辑，url={$scheduleUrlTask->url}";
                                if (is_array($scheduleUrlTask->response_callback) && count($scheduleUrlTask->response_callback) == 2) {
                                    $logger->addInfo($responseLogMsg);
                                    list($class, $action) = $scheduleUrlTask->response_callback;
                                    (new $class)->{$action}($rawResponse, $scheduleUrlTask);
                                }else if ($scheduleUrlTask->response_callback instanceof \Closure) {
                                    $logger->addInfo($responseLogMsg);
                                    call_user_func($scheduleUrlTask->response_callback, $rawResponse, $scheduleUrlTask);
                                }

                                $afterLogMsg = "【{$cron_name}】after_callback-远程请求执行后置逻辑，url={$scheduleUrlTask->url}";
                                if (is_array($scheduleUrlTask->after_callback) && count($scheduleUrlTask->after_callback) == 2) {
                                    $logger->addInfo($afterLogMsg);
                                    list($class, $action) = $scheduleUrlTask->after_callback;
                                    (new $class)->{$action}($scheduleUrlTask);
                                }else if ($scheduleUrlTask->after_callback instanceof \Closure) {
                                    $logger->addInfo($afterLogMsg);
                                    call_user_func($scheduleUrlTask->after_callback, $scheduleUrlTask);
                                }
                            }catch (\Throwable $throwable) {
                                $logger->addError(sprintf("【{$cron_name}】远程请求定时任务处理报错，url={$scheduleUrlTask->url},error=%s, trace=%s", $throwable->getMessage(), $throwable->getTraceAsString()));
                                throw $throwable;
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