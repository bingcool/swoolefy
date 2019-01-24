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
     * __construct
     * @throws \Exception
     */
    public function __construct() {
        parent::__construct();
        // TaskController 仅仅用于http服务，而rpc,websocket,udp服务BService
        if(!BaseServer::isHttpApp()) {
            throw new \Exception("TaskController only use in http server task process!");
        }
    }

}