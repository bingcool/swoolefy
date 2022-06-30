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

class TaskService extends BService
{

    /**
     * $task_id 任务的ID
     * @var int
     */
    public $task_id;

    /**
     * $from_worker_id 记录当前任务from的worker投递
     * @var int
     */
    public $from_worker_id;

    /**
     * \Swoole\Server\Task 对象 swoole4.2.12+ 添加
     * @var \Swoole\Server\Task
     */
    public $task = null;

    /**
     * __construct
     * @throws \Exception
     */
    public function __construct()
    {
        parent::__construct();
        if (!BaseServer::getServer()->taskworker) {
            throw new \Exception(__CLASS__ . " only use in task process");
        }
    }

    /**
     * setTaskId
     * @param int $task_id
     */
    public function setTaskId(int $task_id)
    {
        $this->task_id = $task_id;
    }

    /**
     * setFromWorkerId
     * @param int $from_worker_id
     */
    public function setFromWorkerId(int $from_worker_id)
    {
        $this->from_worker_id = $from_worker_id;
    }

    /**
     * @param \Swoole\Server\Task $task
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
        return $this->task_id;
    }

    /**
     * getFromWorkerId
     * @return int
     */
    public function getFromWorkerId()
    {
        return $this->from_worker_id;
    }

    /**
     * getTask
     * return \Swoole\Server\Task
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