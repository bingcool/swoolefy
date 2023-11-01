<?php
namespace Test\Controller;

use Swoolefy\Core\Controller\BController;
use Swoolefy\Core\Dto\TaskMessageDto;
use Swoolefy\Core\Process\ProcessManager;
use Swoolefy\Core\Task\TaskManager;
use Test\Logger\RunLog;

class ProcessController extends BController
{
    /**
     * 投递异步任务到task进程
     */
    public function sendTaskWorker()
    {
        RunLog::info('sendTaskWorker-log-id='.rand(1,1000),true, ['name'=>'bingcoolhuang']);
        // 投递异步任务到task进程
        $taskMessageDto = new TaskMessageDto();
        $taskMessageDto->taskClass = \Test\Task\TestTask::class;
        $taskMessageDto->taskAction = 'doRun';
        $taskMessageDto->taskData = ['order_id'=>123456,'user_id'=>10000];
        TaskManager::getInstance()->asyncTask($taskMessageDto);
        $this->returnJson(['class' => __CLASS__, 'action'=>__FUNCTION__.'-'.rand(1,10000)]);
    }

    /**
     * worker 进程向自定义进程IPC通信
     */
    public function sendUserWorker($name = '')
    {
        $processName = 'test';
        ProcessManager::getInstance()->writeByProcessName($processName,'hello, Test Process', function ($msg) {
            var_dump($msg);
        });
        $this->returnJson(['class' => __CLASS__, 'action'=>__FUNCTION__]);

    }
}