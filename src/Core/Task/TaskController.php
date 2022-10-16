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

use Swoolefy\Core\BaseServer;
use Swoolefy\Core\EventController;
use Swoolefy\Exception\TaskException;

class TaskController extends EventController
{
    /**
     * $taskId 任务的ID
     * @var int
     */
    protected $taskId;

    /**
     * $fromWorkerId 记录当前任务from的worker投递
     * @var int
     */
    protected $fromWorkerId;

    /**
     * @var \Swoole\Server\Task
     */
    protected $task = null;

    /**
     * /**
     * TaskController应用于http
     * TaskService应用于rpc、websocket、udp
     *
     */
    public function __construct()
    {
        if (!BaseServer::isHttpApp()) {
            throw new TaskException(__CLASS__ . " only use in http server task process");
        }
        if (!BaseServer::getServer()->taskworker) {
            throw new TaskException(__CLASS__ . " only use in task process");
        }
        parent::__construct();
    }

    /**
     * setTaskId
     * @param int $taskId
     * @return void
     */
    public function setTaskId(int $taskId)
    {
        $this->taskId = $taskId;
    }

    /**
     * setFromWorkerId
     * @param int $fromWorkerId
     * @return void
     */
    public function setFromWorkerId(int $fromWorkerId)
    {
        $this->fromWorkerId = $fromWorkerId;
    }

    /**
     * @param \Swoole\Server\Task $task
     * @return void
     */
    public function setTask(\Swoole\Server\Task $task)
    {
        $this->task = $task;
    }

    /**
     * getTaskId
     * @return int
     */
    public function getTaskId()
    {
        return $this->taskId;
    }

    /**
     * getFromWorkerId
     * @return int
     */
    public function getFromWorkerId()
    {
        return $this->fromWorkerId;
    }

    /**
     * getTask
     * @return mixed
     */
    public function getTask()
    {
        return $this->task;
    }

    /**
     * finishTask
     * @param mixed $data
     * @return void
     */
    public function finishTask($data)
    {
        TaskManager::getInstance()->finish($data, $this->task);
    }

}