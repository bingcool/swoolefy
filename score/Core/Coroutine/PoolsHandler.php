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

namespace Swoolefy\Core\Coroutine;

use Swoolefy\Core\Memory\ChannelManager;

abstract class PoolsHandler {

	protected $channel = null;

	protected $pool_name;

	protected $maxPoolsNum;

	protected $minPoolsNum;

	public function setMaxPoolsNum(int $maxPoolsNum = 50) {
		$this->maxPoolsNum = $maxPoolsNum;
	}

	public function setMinPoolsNum(int $minPoolsNum = 20) {
		$this->minPoolsNum = $minPoolsNum;
	}

	public function getMaxPoolsNum() {
		return $this->maxPoolsNum;
	}

	public function getMinPoolsNum() {
		return $this->minPoolsNum;
	}

	public function getPoolName() {
		return $this->pool_name;
	}

	public function getCapacity() {
		return $this->channel->capacity;
	}

	public function getChannel() {
		if(isset($this->channel)) {
			return $this->channel;
		}
	}

	public function registerPools(string $pool_name = null) {
		if($pool_name) {
			$this->pool_name = $pool_name;
			if(!isset($this->channel)) {
                $this->channel = new \Swoole\Coroutine\Channel($this->maxPoolsNum);
        	}
		}
	}

	/**
	 * pushObj 使用完要重新push进channel
	 * @param  object $obj
	 * @return void
	 */
	public function pushObj($obj) {
		if(is_object($obj)) {
			if(!$this->channel->isFull()) {
				$this->channel->push($obj);
			} 
		}
	}

	/**
	 * getObj 开发者自行实现
	 * @return
	 */
	public abstract function getObj();
}