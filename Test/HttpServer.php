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
	 * @param   object $server    
	 * @param   int    $worker_id 
	 * @return  void
	 */
	public function onWorkerStart($server, $worker_id) {}

	/**
	 * onPipeMessage 
	 * @param  object $server
	 * @param  int $src_worker_id
	 * @param  mixed $message
	 * @return void
	 */
	public function onPipeMessage($server, $from_worker_id, $message) {}

    /**
     * Worker Receive Task Process Msg
     *
     * @param \Swoole\Server $server
     * @param int $task_id
     * @param mixed $data
     */
	public function onFinish($server, $task_id, $data)
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