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

namespace Swoolefy\Websocket;

include_once SWOOLEFY_CORE_ROOT_PATH.'/MainEventInterface.php';

use Swoole\Server;
use Swoolefy\Core\Swfy;
use Swoolefy\Websocket\WebsocketServer;
use Swoolefy\Core\WebsocketEventInterface;

abstract class WebsocketEventServer extends WebsocketServer implements WebsocketEventInterface {

    /**
     * __construct
     * @param array $config
     * @throws \Exception
     */
	public function __construct(array $config = []) {
		parent::__construct($config);
	}

	/**
	 * onWorkerStart
	 * @param    Server  $server
	 * @param    int    $worker_id
	 * @return   void
	 */
    abstract public function onWorkerStart($server, $worker_id);

	/**
	 * onOpen 
	 * @param    Server  $server
	 * @param    object  $request
	 * @return   void
	 */
    abstract public function onOpen($server, $request);

    /**
     * onRequest
     * @param \Swoole\Http\Request $request
     * @param \Swoole\Http\Response $response
     * @return void
     * @throws \Throwable
     */
	public function onRequest($request, $response) {
        $app_conf = \Swoolefy\Core\Swfy::getAppConf();
        $appInstance = new \Swoolefy\Core\App($app_conf);
        $appInstance->run($request, $response);
	}

    /**
     * onMessage
     * @param Server $server
     * @param object $frame
     * @return void
     * @throws \Throwable
     */
	public function onMessage($server, $frame) {
		$fd = $frame->fd;
		$data = $frame->data;
		$opcode = $frame->opcode;
		$finish = $frame->finish;
        // 数据接收是否完整
		if($finish) {
			// utf-8文本数据
			if($opcode == WEBSOCKET_OPCODE_TEXT) {
                $app_conf = \Swoolefy\Core\Swfy::getAppConf();
                $appInstance = new \Swoolefy\Websocket\WebsocketHandler($app_conf);
                $appInstance->run($fd, $data);
			}else if($opcode == WEBSOCKET_OPCODE_BINARY) {
				// TODO 二进制数据
				static::onMessageFromBinary($server, $frame);
			}else if($opcode == 0x08) {
				// TODO 关闭帧
				static::onMessageFromClose($server, $frame);
			}
			
		}else {
            // close
            if(method_exists($server,'disconnect')) {
                $server->disconnect($fd, $code = 1009, $reason = "");
            }
        }

	}

    /**
     * onTask 异步任务处理
     * @param Server $server
     * @param int $task_id
     * @param int $from_worker_id
     * @param mixed $data
     * @param mixed $task
     * @return   boolean
     * @throws \Throwable
     */
	public function onTask($server, $task_id, $from_worker_id, $data, $task = null) {
		list($callable, $taskData, $fd) = $data;
        $app_conf = \Swoolefy\Core\Swfy::getAppConf();
        $appInstance = new \Swoolefy\Websocket\WebsocketHandler($app_conf);
        $appInstance->run($fd, [$callable, $taskData], [$from_worker_id, $task_id, $task]);
        return true;
	}

	/**
	 * onFinish 任务完成
	 * @param    Server  $server
	 * @param    int     $task_id
	 * @param    mixed   $data
	 * @return   void
	 */
    abstract public function onFinish($server, $task_id, $data);

	/**
	 * onPipeMessage 
	 * @param    Server  $server
	 * @param    int     $src_worker_id
	 * @param    mixed   $message
	 * @return   void
	 */
    abstract public function onPipeMessage($server, $from_worker_id, $message);

	/**
	 * onClose 连接断开处理
	 * @param    Server  $server
	 * @param    int     $fd
	 * @return   void
	 */
    abstract public function onClose($server, $fd);

	/**
	 * onMessageFromBinary 处理二进制数据
	 * @param  Server $server
	 * @param  mixed $frame
	 * @return void       
	 */
    abstract public function onMessageFromBinary($server, $frame);

	/**
	 * onMessageFromClose 处理关闭帧
	 * @param  Server $server
	 * @param  mixed $frame
	 * @return void       
	 */
    abstract public function onMessageFromClose($server, $frame);

}
