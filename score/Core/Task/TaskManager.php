<?php
namespace Swoolefy\Core\Task;

use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Task\AsyncTask;
use Swoolefy\Core\Task\AppAsyncTask;

class TaskManager {

	/**
	 * asyncTask 异步任务投递
	 * @param  mixed  $callable
	 * @param  mixed  $data
	 * @return mixed
	 */
	public static function asyncTask($callable, $data = []) {
		if(BaseServer::getServiceProtocol() == SWOOLEFY_HTTP) {
			return AppAsyncTask::registerTask($callable, $data);
		}else {
			return AsyncTask::registerTask($callable, $data);
		}
	}
}