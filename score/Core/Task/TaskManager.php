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

use Swoolefy\Core\Task\AsyncTask;

class TaskManager {
	
	use \Swoolefy\Core\SingletonTrait;

	/**
	 * asyncTask 异步任务投递
	 * @param  mixed  $callable
	 * @param  mixed  $data
	 * @return mixed
	 */
	public static function asyncTask($callable, $data = []) {
		return AsyncTask::registerTask($callable, $data);
	}

	/**
	 * finish 异步任务完成，退出至worker进程
	 * @param    mixed  $callable
	 * @param    mixed  $data
	 * @return   mixed
	 */
	public static function finish($data = null) {
		return AsyncTask::finish($data);
	}

	/**
	 * registerTaskfinish 异步任务完成，退出至worker进程
	 * @param    mixed  $callable
	 * @param    mixed  $data
	 * @return   mixed
	 */
	public static function registerTaskfinish($data = null) {
		return AsyncTask::registerTaskfinish($data);
	}
}