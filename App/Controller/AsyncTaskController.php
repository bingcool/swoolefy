<?php
namespace App\Controller;

use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Core\Task\TaskManager;

/**
 * 异步任务处理类，在worker中执行
 */
class AsyncTaskController extends BController {
		
		// 测试投递注册异步任务
	public function asyncTask() {
		dump('测试异步任务');
		// 注册任务并执行
		TaskManager::asyncTask(['App/Task/AsyncTask', 'asyncTaskTest'], ['swoole']);
		return ;
	}

	
}