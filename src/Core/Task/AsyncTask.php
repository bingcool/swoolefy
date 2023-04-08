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

namespace Swoolefy\Core\Task;

use Swoolefy\Core\BService;
use Swoolefy\Core\EventController;
use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Dto\TaskMessageDto;
use Swoolefy\Exception\TaskException;

class AsyncTask implements AsyncTaskInterface
{

    /**
     * @param TaskMessageDto $taskMessageDto
     * @return mixed
     * @throws TaskException
     */
    public static function registerTask(TaskMessageDto $taskMessageDto)
    {
        if (empty($taskMessageDto->taskClass)) {
            throw new TaskException("Missing TaskMessageDto->taskClass Params");
        }

        if (!is_subclass_of($taskMessageDto->taskClass,EventController::class) && !is_subclass_of($taskMessageDto->taskClass,BService::class)) {
            throw new TaskException("TaskMessageDto->taskClass Need extends EventController or BService.");
        }

        if (empty($taskMessageDto->taskAction)) {
            throw new TaskException("Missing TaskMessageDto->taskAction Params");
        }

        if (!is_array($taskMessageDto->taskData)) {
            throw new TaskException("TaskMessageDto->taskData Type Error");
        }

        $taskMessageDto->taskClass = str_replace('/', '\\', trim($taskMessageDto->taskClass, '/'));

        $fd = is_object(Application::getApp()) ? Application::getApp()->getFd() : null;
        if (BaseServer::isUdpApp()) {
            /**
             * @var \Swoolefy\Udp\UdpHandler $app
             */
            $app = Application::getApp();
            if (is_object($app)) $fd = $app->getClientInfo();
        }

        if (BaseServer::isHttpApp()) {
            $fd = is_object(Application::getApp()) ? Application::getApp()->request->fd : null;
        }

        $taskId = Swfy::getServer()->task(serialize(
            [
                [$taskMessageDto->taskClass, $taskMessageDto->taskAction],
                $taskMessageDto->taskData,
                $fd
            ]
        ));

        return $taskId;
    }

    /**
     * registerTaskFinish 异步任务完成并退出到worker进程
     * @param mixed $data
     * @param mixed $task
     * @return void
     */
    public static function registerTaskFinish($data, $task = null)
    {
        static::finish($data, $task);
    }

    /**
     * finish registerTaskFinish函数-异步任务完成并退出到worker进程的别名函数
     * @param mixed $data
     * @param mixed $task
     * @return void
     */
    public static function finish($data, $task = null)
    {
        if (is_array($data)) {
            $data = json_encode($data, JSON_UNESCAPED_UNICODE);
        }

        if (BaseServer::isTaskEnableCoroutine() && $task instanceof \Swoole\Server\Task) {
            $task->finish($data);
        } else {
            Swfy::getServer()->finish($data);
        }
    }

    /**
     * getCurrentWorkerId 获取当前执行进程的id
     * @return int
     */
    public static function getCurrentWorkerId(): int
    {
        return Swfy::getServer()->worker_id;
    }

    /**
     * isWorkerProcess 判断当前进程是否是worker进程
     * @return bool
     * @throws \Exception
     */
    public static function isWorkerProcess(): bool
    {
        return Swfy::isWorkerProcess();
    }

    /**
     * isTaskProcess 判断当前进程是否是异步task进程
     * @return bool
     * @throws \Exception
     */
    public static function isTaskProcess(): bool
    {
        return Swfy::isTaskProcess();
    }
}