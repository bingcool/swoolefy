<?php
/**
+----------------------------------------------------------------------
| swoolefy framework bases on swoole extension development, we can use it easily!
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
+----------------------------------------------------------------------
*/

namespace Swoolefy\Core\Task;


use Swoolefy\Core\BaseServer;
use Swoolefy\Core\EventController;

class TaskController extends EventController {
    /**
     * $task_id 任务的ID
     * @var null
     */
    public $task_id;

	/**
	 * $from_worker_id 记录当前任务from的woker投递
	 * @var null
	 */
	public $from_worker_id;

    /**
 * \Swoole\Server\Task 对象 swoole4.2.12+ 添加
 * @var
 */
    public $task = null;

    /**
     * __construct
     * @throws \Exception
     */
    public function __construct() {
        parent::__construct();
        // TaskController 仅仅用于http服务，而rpc,websocket,udp服务TaskService
        if(!BaseServer::isHttpApp()) {
            throw new \Exception("TaskController only use in http server task process!");
        }
    }

    /**
     * setTaskId
     * @param int $task_id
     */
    public function setTaskId(int $task_id) {
        $this->task_id = $task_id;
    }

    /**
     * setFromWorkerId
     * @return int
     */
    public function setFromWorkerId(int $from_worker_id) {
        $this->from_worker_id = $from_worker_id;
    }

    /**
     * @param \Swoole\Server\Task $task
     */
    public function setTask(\Swoole\Server\Task $task) {
        $this->task = $task;
    }

    /**
     * getTaskId
     * @return int
     */
    public function getTaskId() {
        return $this->task_id;
    }

    /**
     * getFromWorkerId
     * @return int
     */
    public function getFromWorkerId() {
        return $this->from_worker_id;
    }

    /**
     * getTask
     * return mixed
     */
    public function getTask() {
        return $this->task;
    }

}