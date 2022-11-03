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
use Swoolefy\Core\BService;
use Swoolefy\Exception\TaskException;

class TaskService extends BService
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
     * \Swoole\Server\Task
     * @var \Swoole\Server\Task
     */
    protected $task = null;

    /**
     * __construct
     * @throws \Exception
     */
    public function __construct()
    {
        parent::__construct();
        if (!BaseServer::getServer()->taskworker) {
            throw new TaskException(__CLASS__ . " only use in task process");
        }
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
     * @return mixed
     */
    public function getTaskData()
    {
        return $this->getMixedParams();
    }

    /**
     * getTaskId
     * @return int
     */
    public function getTaskId(): int
    {
        return $this->taskId;
    }

    /**
     * getFromWorkerId
     * @return int
     */
    public function getFromWorkerId(): int
    {
        return $this->fromWorkerId;
    }

    /**
     * getTask
     * @return \Swoole\Server\Task|null
     */
    public function getTask(): \Swoole\Server\Task
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