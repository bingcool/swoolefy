<?php
namespace Test\Controller;

use Swoolefy\Annotation\ApiOperation;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Core\Dto\TaskMessageDto;
use Swoolefy\Core\Process\ProcessManager;
use Swoolefy\Core\Task\TaskManager;
use Test\Logger\RunLog;

class ProcessController extends BController
{
    /**
     * @Api 测试投递异步任务到 task 进程
     *
     * curl -X GET 'http://127.0.0.1:9501/api/send-task-worker'
     */
    #[ApiOperation(description: '测试投递异步任务到 task 进程')]
    public function sendTaskWorker(): array
    {
        RunLog::info('sendTaskWorker-log-id='.rand(1,1000),['name'=>'bingcoolhuang'],true);
        // 投递异步任务到task进程
        $taskMessageDto = new TaskMessageDto();
        $taskMessageDto->taskClass = \Test\Task\TestTask::class;
        $taskMessageDto->taskAction = 'doRun';
        $taskMessageDto->taskData = ['order_id'=>123456,'user_id'=>10000];
        TaskManager::getInstance()->asyncTask($taskMessageDto);
        return ['class' => __CLASS__, 'action'=>__FUNCTION__.'-'.rand(1,10000)];
    }

    /**
     * @Api 测试 worker 向自定义进程 IPC 通信
     *
     * curl -X GET 'http://127.0.0.1:9501/api/send-user-worker?name=xxx'
     */
    #[ApiOperation(description: '测试 worker 向自定义进程 IPC 通信')]
    public function sendUserWorker($name = ''): array
    {
        $processName = 'test';
        ProcessManager::getInstance()->writeByProcessName($processName,'hello, Test Process', function ($msg) {
            var_dump($msg);
        });
        return ['class' => __CLASS__, 'action'=>__FUNCTION__];

    }
}
