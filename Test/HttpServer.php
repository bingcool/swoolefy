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

namespace Test;

use Swoole\Http\Server;

class HttpServer extends \Swoolefy\Http\HttpAppServer {

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
	 * @param int $worker_id
	 * @return  void
	 */
	public function onWorkerStart(Server $server, int $worker_id) {}

	/**
	 * onPipeMessage 
	 * @param  Server $server
	 * @param  int $src_worker_id
	 * @param  mixed $message
	 * @return void
	 */
	public function onPipeMessage(Server $server, int $from_worker_id, $message) {}

    /**
     * Worker Receive Task Process Msg
     *
     * @param Server $server
     * @param int $task_id
     * @param mixed $data
     */
	public function onFinish(Server $server, int $task_id, $data)
    {
        var_dump('Finish-cid = '.\Swoole\Coroutine::getCid());
        //var_dump('Worker Process onFinish Receive Task Worker msg='.$data);
        $userId = $data['user_id'];
        $db = Factory::getDb();
        $result = $db->newQuery()->table('tbl_users')->where(['user_id' => $userId])->find();
        //var_dump('Worker Process Find Db Result as：');
        print_r($result);
    }
}	