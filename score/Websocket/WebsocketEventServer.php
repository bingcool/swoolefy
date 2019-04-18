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

namespace Swoolefy\Websocket;

include_once SWOOLEFY_CORE_ROOT_PATH.'/MainEventInterface.php';

use Swoolefy\Core\Swfy;
use Swoolefy\Websocket\WebsocketServer;
use Swoolefy\Core\WebsocketEventInterface;

abstract class WebsocketEventServer extends WebsocketServer implements WebsocketEventInterface {
	/**
	 * __construct 初始化
	 * @param array $config
	 */
	public function __construct(array $config = []) {
		parent::__construct($config);
	}

	/**
	 * onWorkerStart worker启动函数处理
	 * @param    object  $server
	 * @param    int    $worker_id
	 * @return   void
	 */
	public abstract function onWorkerStart($server, $worker_id);

	/**
	 * onOpen 
	 * @param    object  $server
	 * @param    object  $request
	 * @return   void
	 */
	public abstract function onOpen($server, $request);

	/**
	 * onRequest 接受http请求处理
	 * @param    object  $request
	 * @param    object  $response
	 * @return   void
	 */
	public function onRequest($request, $response) {
	    if(isset(self::$config['accept_http']) && self::$config['accept_http'] == true) {
            $AppConfig = \Swoolefy\Core\Swfy::getAppConf();
            $appInstance = new \Swoolefy\Core\App($AppConfig);
            $appInstance->run($request, $response);
        }
	}

	/**
	 * onMessage 接受信息并处理信息
	 * @param    object  $server
	 * @param    object  $frame
	 * @return   void
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
                $config = \Swoolefy\Core\Swfy::getAppConf();
                $appInstance = new \Swoolefy\Websocket\WebsocketHander($config);
                $appInstance->run($fd, $data);
			}else if($opcode == WEBSOCKET_OPCODE_BINARY) {
				// TODO 二进制数据
				static::onMessageFromBinary($server, $frame);
			}else if($opcode == 0x08) {
				// TODO 关闭帧
				static::onMessageFromClose($server, $frame);
			}
			
		}else {
			// 断开连接
			$server->close();
		}
		
	}

	/**
	 * onTask 异步任务处理
	 * @param    object  $server
	 * @param    int     $task_id
	 * @param    int     $from_worker_id
	 * @param    mixed   $data
     * @param    mixed   $task
	 * @return   boolean
	 */
	public function onTask($server, $task_id, $from_worker_id, $data, $task = null) {
		list($callable, $taskData, $fd) = $data;
        $AppConfig = \Swoolefy\Core\Swfy::getAppConf();
        $appInstance = new \Swoolefy\Websocket\WebsocketHander($AppConfig);
        $appInstance->run($fd, [$callable, $taskData], [$from_worker_id, $task_id, $task]);
        return true;
	}

	/**
	 * onFinish 任务完成
	 * @param    object  $server
	 * @param    int     $task_id
	 * @param    mixed   $data
	 * @return   void
	 */
	public abstract function onFinish($server, $task_id, $data);

	/**
	 * onPipeMessage 
	 * @param    object  $server
	 * @param    int     $src_worker_id
	 * @param    mixed   $message
	 * @return   void
	 */
	public abstract function onPipeMessage($server, $from_worker_id, $message);

	/**
	 * onClose 连接断开处理
	 * @param    object  $server
	 * @param    int     $fd
	 * @return   void
	 */
	public abstract function onClose($server, $fd);

	/**
	 * onMessageFromBinary 处理二进制数据
	 * @param  object $server
	 * @param  mixed $frame
	 * @return void       
	 */
	public abstract function onMessageFromBinary($server, $frame);

	/**
	 * onMessageFromClose 处理关闭帧
	 * @param  object $server
	 * @param  mixed $frame
	 * @return void       
	 */
	public abstract function onMessageFromClose($server, $frame);

}
