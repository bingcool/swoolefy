<?php
namespace Swoolefy\Rpc;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Swoole;
use Swoolefy\Rpc\RpcDispatch;
use Swoolefy\Core\HanderInterface;

class RpcHander extends Swoole implements HanderInterface {

	public $header = [];

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
	 * run 完成初始化后,开始路由匹配和创建访问实例
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
			if(Swfy::$server->pack_check_type == 'length') {
				list($header, $body) = $recv;
				$this->header = $header;
				if(is_array($body) && count($body) == 2) {
					list($callable, $params) = $body;
				}
			}else if(Swfy::$server->pack_check_type == 'eof'){
				// eof方式
				if(is_array($recv) && count($recv) == 2) {
					list($callable, $params) = $recv;
				}
			}else {

			}
		}else {
			// 任务task进程
			list($callable, $params) = $recv;
		}
		
		if($callable && $params) {
			$Dispatch = new RpcDispatch($callable, $params);
			$Dispatch->dispatch();
		}
		// 必须执行
		parent::end();
		return;
	}

	/**
	 * author 认证
	 * @return 
	 */
	public function author() {

	}


}