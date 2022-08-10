<?php
namespace Test\Controller;

use Swoolefy\Core\Controller\BController;
use Swoolefy\Core\Dto\TaskMessageDto;
use Swoolefy\Core\Process\ProcessManager;
use Swoolefy\Core\Task\TaskManager;

class ProcessController extends BController
{
    /**
     * 投递异步任务到task进程
     */
    public function sendTaskWorker()
    {
        // 投递异步任务到task进程

        $taskMessageDto = new TaskMessageDto();
        $taskMessageDto->taskClass = \Test\Task\TestTask::class;
        $taskMessageDto->taskAction = 'doRun';
        $taskMessageDto->taskData = ['order_id'=>123456,'user_id'=>10000];
        TaskManager::getInstance()->asyncTask($taskMessageDto);

       $this->returnJson(['class' => __CLASS__, 'action'=>__FUNCTION__]);
    }

    /**
     * worker 进程向自定义进程IPC通信
     */
    public function sendUserWorker()
    {
        $processName = 'test';

        ProcessManager::getInstance()->writeByProcessName($processName,'hello, Test Process', function ($msg) {
            var_dump($msg);
        });

        $this->returnJson(['class' => __CLASS__, 'action'=>__FUNCTION__]);

    }
}