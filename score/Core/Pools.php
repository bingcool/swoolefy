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

namespace Swoolefy\Core;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Pools\PoolsManager;

class Pools {

	use \Swoolefy\Core\SingletonTrait;

	private $poolsName = [];
	
	private $pools = [];

	private $size = [];

	/**
	 * registerPools  注册pools的通道
	 * @return Channel
	 */
	public function registerPools(string $poolsName, int $size = 5 * 1024 * 1024) {
		$conf = Swfy::getConf();
		if(isset($conf['setting']['worker_num'])) {
			$channel_num = $conf['setting']['worker_num'];
		}
		for($i=0; $i<$channel_num; $i++) {
			if(!isset($this->pools[$poolsName][$i]) && isset($size) && !empty($size)) {
				$this->pools[$poolsName][$i] = new \Swoole\Channel($size);
				$this->poolsName[$poolsName] = $poolsName;
				$this->size[$poolsName] = $size;
			}
		}
		if($this->pools[$poolsName]) {
			return $this->pools[$poolsName];
		}
		
	}

	/**
	 * getObj 获取pools的一个实例
	 * @param  string $poolsName
	 * @return mixed
	 */
	public function getObj(string $poolsName, int $timeout = 10) {
		if(Swfy::isWorkerProcess()) {
			$worker_id = Swfy::getCurrentWorkerId();
			if(isset($this->pools[$poolsName][$worker_id])) {
				$chan = $this->pools[$poolsName][$worker_id];
				$obj = '';
				$start_time = time();
				while(1) {
					$obj = $chan->pop();
					if(is_object($obj)) {
						$this->sendMessage($poolsName);
						break;
					}else {
                        if((time() - $start_time) > $timeout) {
                            break;
                        }
						usleep(1000);
					}
				}
				return $obj;
			}
		}
		return null;
	}

	/**
	 * getChanStats 获取某个通道的状态，返回一个数组，包括2项信息，queue_num 通道中的元素数量，queue_bytes 通道当前占用的内存字节数
	 * @param  string $poolName
	 * @return mixed
	 */
	public function getChanStats(string $poolName, int $worker_id) {
		if(isset($this->pools[$poolName][$worker_id])) {
			$chan = $this->pools[$poolName][$worker_id];
			return $chan->stats();
		}
		return null;
	}

	/**
	 * getPools 获取所有进程池的channel对象
	 * @return array
	 */
	public function getPools(string $poolsName) {
		if($poolsName) {
			return $this->pools[$poolsName];
		}
		return $this->pools;
	}

	/**
	 * getChanSize 获取设置的通道的大小，单位字节
	 * @param  string   $poolName
	 * @return int
	 */
	public function getChanSize(string $poolsName = null) {
		$chanSize = isset($poolsName) ? $this->size[$poolsName] : $this->size;
		return $chanSize;
	}

	/**
	 * sendMessage 通知process创建实例
	 * @return void
	 */
	public function sendMessage(string $poolsName, $msg = null) {
		if($msg == null) {
			$msg = 1;
		}
		PoolsManager::getInstance()->writeByProcessPoolsName($poolsName, $msg);
	}
}