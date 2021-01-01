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

namespace Swoolefy\Http;

use Swoole\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoolefy\Core\Task\TaskController;

abstract class HttpAppServer extends \Swoolefy\Http\HttpServer {

    /**
     * __construct
     * @param array $config
     * @throws \Exception
     */
	public function __construct(array $config=[]) {
		parent::__construct($config);
	}

	/**
	 * onWorkerStart 
	 * @param Server $server
	 * @param int  $worker_id
	 * @return void
	 */
    abstract public function onWorkerStart($server, $worker_id);

	/**
	 * onRequest 
	 * @param  Request  $request
	 * @param  Response  $response
     * @throws \Throwable
	 * @return boolean
	 */
	public function onRequest($request, $response) {
		if($request->server['path_info'] == '/favicon.ico' || $request->server['request_uri'] == '/favicon.ico') {
            $response->end();
            return true;
       	}
       	try {
            $app_conf = \Swoolefy\Core\Swfy::getAppConf();
            $appInstance = new \Swoolefy\Core\App($app_conf);
            $appInstance->run($request, $response);
        }catch (\Throwable $throwable) {
            throw $throwable;
        }
	}

	/**
	 * onPipeMessage 
	 * @param  Server  $server
	 * @param  int     $src_worker_id
	 * @param  mixed   $message
	 * @return void
	 */
    abstract public function onPipeMessage($server, $from_worker_id, $message);

	/**
	 * onTask 异步任务处理
     * @param  Server  $server
	 * @param  int  $task_id
	 * @param  int  $from_worker_id
	 * @param  mixed $data
     * @param  mixed $task
     * @throws \Throwable
	 * @return void
	 */
	public function onTask($server, $task_id, $from_worker_id, $data, $task = null) {
	    try {
            list($callable, $extend_data, $fd) = $data;
            list($class, $action) = $callable;
            /**@var TaskController $taskInstance*/
            $taskInstance = new $class;
            $taskInstance->setTaskId((int)$task_id);
            $taskInstance->setFromWorkerId((int)$from_worker_id);
            $task && $taskInstance->setTask($task);
            $taskInstance->$action($extend_data);
            if(!$taskInstance->isDefer()) {
                $taskInstance->end();
            }
            unset($callable, $extend_data, $fd);
        }catch (\Throwable $throwable) {
	        throw $throwable;
        }
	}

	/**
	 * onFinish
     * @param  Server $server
	 * @param  int   $task_id
	 * @param  mixed $data
	 * @return void
	 */
	public function onFinish($server, $task_id, $data) {}

}	