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
use Swoolefy\Core\Application;

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
        $msg = json_decode($data, true) ?? $data;

        var_dump('Worker Process onFinish Receive Task Worker msg='.$data);

        $userId = $msg['user_id'];
        /**
         * @var \Common\Library\Db\Mysql $db
         */
        $db = Application::getApp()->get('db');
        $result = $db->createCommand('select * from tbl_users where user_id=:user_id limit 1')->queryAll([
            ':user_id' => $userId
        ]);

        var_dump('Worker Process Find Db Result as：');
        print_r($result);
    }
}	