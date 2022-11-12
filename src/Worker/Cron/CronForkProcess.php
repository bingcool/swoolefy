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

    const FORK_TYPE_EXEC = 'exec';

    const FORK_TYPE_PROC_OPEN = 'proc_open';

    /**
     * @var string
     */
    protected $forkType = self::FORK_TYPE_PROC_OPEN;

    /**
     * @var array
     */
    protected $params = [];

    /**
     * onInit
     * @return void
     */
    public function onInit()
    {
        parent::onInit();
        $this->params   = $this->getArgs()['params'] ?? [];
        $this->forkType = $this->getArgs()['fork_type'] ?? self::FORK_TYPE_PROC_OPEN;
    }

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
                                if($runner->isNextHandle(false)) {
                                    if($this->forkType == self::FORK_TYPE_PROC_OPEN) {
                                        $runner->procOpen(function ($pipe0, $pipe1, $pipe2, $status, $returnCode) use($task) {
                                            $this->receiveCallBack($pipe0, $pipe1, $pipe2, $status, $returnCode, $task);
                                        } , $task['exec_bin_file'], $task['exec_script'], $this->params);
                                    }else {
                                        $runner->exec($task['exec_bin_file'], $task['exec_script'], $this->params, true);
                                    }
                                }
                            }catch (\Exception $exception)
                            {
                                $this->onHandleException($exception, $task);
                            }
                        });
                    }catch (\Throwable $throwable) {
                        $this->onHandleException($throwable, $task);
                    }
                }
            }
        }catch (\Throwable $throwable) {
            $this->onHandleException($throwable);
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