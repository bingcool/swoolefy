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

namespace Swoolefy\Rpc;

include_once SWOOLEFY_CORE_ROOT_PATH.'/MainEventInterface.php';

use Swoole\Server;
use Swoolefy\Tcp\TcpServer;
use Swoolefy\Core\RpcEventInterface;

abstract class RpcServer extends TcpServer implements RpcEventInterface {
    /**
     * __construct 初始化
     * @param array $config
     * @throws \Exception
     */
	public function __construct(array $config = []) {
		parent::__construct($config);
	}

	/**
	 * onWorkerStart worker进程启动时回调处理
	 * @param  Server $server
	 * @param  int    $worker_id
	 * @return void       
	 */
    abstract public function onWorkerStart($server, $worker_id);

	/**
	 * onConnect socket连接上时回调函数
	 * @param  Server $server
	 * @param  int    $fd    
	 * @return void        
	 */
    abstract public function onConnect($server, $fd);

    /**
     * onReceive 接收数据时的回调处理，$data是一个完整的数据包，底层已经封装好，只需要配置好，直接使用即可
     * @param Server $server
     * @param int $fd
     * @param int $reactor_id
     * @param mixed $data
     * @return boolean
     * @throws \Throwable
     */
	public function onReceive($server, $fd, $reactor_id, $data) {
        $app_conf = \Swoolefy\Core\Swfy::getAppConf();
        $appInstance = new \Swoolefy\Rpc\RpcHandler($app_conf);
        $appInstance->run($fd, $data);
        return true;
	}

    /**
     * onTask 任务处理函数调度
     * @param Server $server
     * @param int $task_id
     * @param int $from_worker_id
     * @param mixed $data
     * @param mixed $task
     * @return  boolean
     * @throws \Throwable
     */
	public function onTask($server, $task_id, $from_worker_id, $data, $task = null) {
		list($callable, $taskData, $fd) = $data;
        $app_conf = \Swoolefy\Core\Swfy::getAppConf();
        $appInstance = new \Swoolefy\Rpc\RpcHandler($app_conf);
        $appInstance->run($fd, [$callable, $taskData], [$from_worker_id, $task_id, $task]);
		return true;
	}

    /**
     * onFinish 异步任务完成后调用
     * @param Server $server
     * @param $task_id
     * @param $data
     * @return mixed
     */
    abstract public function onFinish($server, $task_id, $data);

	/**
	 * onPipeMessage 
	 * @param Server $server
	 * @param int    $src_worker_id
	 * @param mixed  $message
	 * @return void
	 */
    abstract public function onPipeMessage($server, $from_worker_id, $message);

	/**
	 * onClose tcp连接关闭时回调函数
	 * @param  Server $server
	 * @param  int    $fd    
	 * @return void
	 */
    abstract public function onClose($server, $fd);
	
}
