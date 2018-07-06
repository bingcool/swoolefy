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

use Swoolefy\Core\EventController;

// TaskController 仅仅用于http服务，而rpc,websocket,udp服务BService
class TaskController extends EventController {

	/**
	 * $from_worker_id 记录当前任务from的woker投递
	 * @see https://wiki.swoole.com/wiki/page/134.html
	 * @var null
	 */
	public $from_worker_id = null;

	/**
	 * $task_id 任务的ID
	 * @see  https://wiki.swoole.com/wiki/page/134.html
	 * @var null
	 */
	public $task_id = null;
}