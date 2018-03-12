<?php
namespace App\Controller;

use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Core\Task\AppAsyncTask;

/**
 * 异步任务处理类，在worker中执行
 */
class AsyncTaskController extends BController {
		
		// 测试投递注册异步任务
	public function asyncTask() {
		// 注册任务并执行
		AppAsyncTask::registerTask(['App/Task/AsyncTask', 'asyncTaskTest'], ['swoole']);

		return ;
	}

		// 测试投递静态调用异步任务
	public function asyncStaticTask() {
		AppAsyncTask::registerStaticCallTask(['App/Task/AsyncTask','asyncStaticTest'], ['hello']);
		return ;
	}

	
}