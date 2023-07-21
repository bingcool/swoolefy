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

use Common\Library\HttpClient\CurlHttpClient;
use Swoolefy\Core\CommandRunner;
use Swoolefy\Core\Crontab\CrontabManager;

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
        if(!empty($this->taskList)) {
            foreach($this->taskList as $task) {
                try {
                    CrontabManager::getInstance()->addRule($task['cron_name'], $task['cron_expression'], function ($cron_name, $expression) use($task) {
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

                        if (is_array($task['callback']) && count($task['callback']) == 2) {
                            list($class, $action) = $task['callback'];
                            (new $class)->{$action}($rawResponse, $task);
                        } else if (isset($task['callback']) && $task['callback'] instanceof \Closure) {
                            call_user_func($task['callback'], $rawResponse, $task);
                        }
                    });
                }catch (\Throwable $throwable) {
                    $this->onHandleException($throwable, $task);
                }
            }
        }
    }

    /**
     * receive cli process return CallBack handle
     *
     * @param $pipe0
     * @param $pipe1
     * @param $pipe2
     * @param $status
     * @param $returnCode
     * @param $task
     */
    protected function receiveCallBack($pipe0, $pipe1, $pipe2, $status, $returnCode, $task)
    {

    }
}