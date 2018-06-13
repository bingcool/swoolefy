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

namespace Swoolefy\Rpc;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Swoole;
use Swoolefy\Core\ServiceDispatch;
use Swoolefy\Core\HanderInterface;

class RpcHander extends Swoole implements HanderInterface {

	/**
	 * $header length方式packet检测时，可以寄存请求包的信息，用于认证等
	 * @var array
	 */
	public $header = [];

	/**
	 * __construct 初始化
	 * @param    array  $config
	 */
	public function __construct(array $config=[]) {
		parent::__construct($config);
	}

	/**
	 * init 当执行run方法时,首先会执行init->bootstrap
	 * @param  mixed  $recv
	 * @return void       
	 */
	public function init($recv) {}

	/**
	 * bootstrap 当执行run方法时,首先会执行init->bootstrap
	 * @param  mixed  $recv
	 * @return void
	 */
	public function bootstrap($recv) {}

	/**
	 * run 完成初始化后,路由匹配和创建访问实例
	 * @param  int   $fd
	 * @param  mixed $recv
	 * @return mixed
	 */
	public function run($fd, $recv) {
		// 必须要执行父类的run方法
		parent::run($fd, $recv);
		// 当前进程worker进程
		if($this->isWorkerProcess()) {
			// packet_length_checkout方式
			if(Swfy::$server->pack_check_type == SWOOLEFY_PACK_CHECK_LENGTH) {
				list($header, $body) = $recv;
				$this->header = $header;
				if(is_array($body) && count($body) == 2) {
					list($callable, $params) = $body;
				}

				if($this->ping()) {
					$args = ['pong', $this->header];
					$data = \Swoolefy\Tcp\TcpServer::pack($args);
					Swfy::getServer()->send($this->fd, $data);
					return;
				}
				
			}else if(Swfy::$server->pack_check_type == SWOOLEFY_PACK_CHECK_EOF){
				// eof方式
				if(is_array($recv) && count($recv) == 2) {
					list($callable, $params) = $recv;
				}
			}else {
				// TODO
				// 其他方式处理
			}
		}else {
			// 任务task进程
			list($callable, $params) = $recv;
		}
		
		if($callable && $params) {
			$Dispatch = new ServiceDispatch($callable, $params, $this->header);
			$Dispatch->dispatch();
		}
		// 必须执行
		parent::end();
		return;
	}

	/**
	 * ping 心跳检测
	 * @return   
	 */
	public function ping() {
		if($this->header['request_id'] == 'ping') {
			return true;
		}
		return false;
	}

	/**
	 * author 认证
	 * @return 
	 */
	public function author() {}

}