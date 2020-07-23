<?php
/**
+----------------------------------------------------------------------
| swoolefy framework bases on swoole extension development, we can use it easily!
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| @see https://github.com/bingcool/swoolefy
+----------------------------------------------------------------------
*/

namespace Swoolefy\Core\Task;

use Swoolefy\Core\Task\AsyncTask;

class TaskManager {
	
	use \Swoolefy\Core\SingletonTrait;

	/**
	 * asyncTask 异步任务投递
	 * @param mixed $callable
	 * @param mixed $data
	 * @return mixed
	 */
	public static function asyncTask($callable, $data = []) {
		return AsyncTask::registerTask($callable, $data);
	}

	/**
	 * finish 异步任务完成，退出至worker进程
	 * @param mixed $data
	 * @param mixed $task
     * @return void
	 */
	public static function finish($data = null, $task = null) {
		AsyncTask::finish($data, $task);
	}

	/**
	 * registerTaskFinish 异步任务完成，退出至worker进程
	 * @param mixed $data
	 * @param mixed $task
     * @return void
	 */
	public static function registerTaskFinish($data = null, $task = null) {
		AsyncTask::registerTaskfinish($data, $task);
	}
}