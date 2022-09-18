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

use Swoolefy\Core\CommandRunner;
use Swoolefy\Core\Crontab\CrontabManager;

class CronForkProcess extends CronProcess
{
    /**
     * run
     */
    public function run()
    {
        try {
            parent::run();
            if(!empty($this->taskList)) {
                foreach($this->taskList as $task) {
                    try {
                        CrontabManager::getInstance()->addRule($task['cron_name'], $task['cron_expression'], function ($cron_name, $expression) use($task) {
                            $runner = CommandRunner::getInstance($cron_name,1);
                            try {
                                if($runner->isNextHandle(false))
                                {
                                    $execFile = $task['run_cli'];
                                    $runner->procOpen(function ($pipe0, $pipe1, $pipe2, $status, $returnCode) use($task) {
                                        $this->receiveCallBack($pipe0, $pipe1, $pipe2, $status, $returnCode, $task);
                                    } , $execFile, []);
                                }
                            }catch (\Exception $e)
                            {
                                $this->onHandleException($e, $task);
                            }
                        });
                    }catch (\Throwable $exception) {
                        $this->onHandleException($exception, $task);
                    }
                }
            }
        }catch (\Throwable $exception) {
            $this->onHandleException($exception);
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