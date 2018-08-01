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
		if(!isset($this->pools[$poolsName]) && isset($size) && !empty($size)) {
			$this->pools[$poolsName] = new \Swoole\Channel($size);
			$this->poolsName[$poolsName] = $poolsName;
			$this->size[$poolsName] = $size;
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
	public function getObj(string $poolsName) {
		if(isset($this->pools[$poolsName])) {
			$chan = $this->pools[$poolsName];
			$obj = '';
			while(1) {
				$obj = $chan->pop();
				if(is_object($obj)) {
					$this->sendMessage($poolsName);
					break;
				}else {
					usleep(200);
				}
			}
			return $obj;
		}
	}

	/**
	 * getPoolsName 获取某一个进程池的名称
	 * @param  string $poolsName  
	 * @return [type]            [description]
	 */
	public function getPoolsName(string $poolsName) {
		return $this->poolsName[$poolsName];
	}

	/**
	 * sendMessage 通知process创建实例
	 * @return void
	 */
	public function sendMessage(string $poolsName) {
		PoolsManager::getInstance()->writeByRandom($poolsName, 1);
	}
}