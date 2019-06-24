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

abstract class PoolsHandler {

	protected $channel = null;

	protected $pool_name;

	protected $maxPoolsNum;

	protected $minPoolsNum;

	protected $pushTimeout = 3;

	protected $popTimeout = 1;

	protected $callCount = 0;

	public function setMaxPoolsNum(int $maxPoolsNum = 50) {
		$this->maxPoolsNum = $maxPoolsNum;
	}

	public function getMaxPoolsNum() {
		return $this->maxPoolsNum;
	}

    public function setMinPoolsNum(int $minPoolsNum = 20) {
        $this->minPoolsNum = $minPoolsNum;
    }

    public function getMinPoolsNum() {
		return $this->minPoolsNum;
	}

	public function setPushTimeout(int $pushTimeout = 3) {
	    $this->pushTimeout = $pushTimeout;
    }

    public function getPushTimeout() {
	    return $this->pushTimeout;
    }

    public function setPopTimeout(int $popTimeout = 1) {
	    $this->popTimeout = $popTimeout;
    }

    public function getPopTimeout() {
	    return $this->popTimeout;
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

    /**
     * @param string|null $pool_name
     */
	public function registerPools(string $pool_name = null) {
		if($pool_name) {
			$this->pool_name = trim($pool_name);
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
		    go(function() use($obj) {
                $result = $this->channel->push($obj, $this->pushTimeout);
                $length = $this->channel->length();
                // 矫正
                if(($this->maxPoolsNum - $length) == $this->callCount - 1) {
                	$this->callCount--;
                }else {
                	$this->callCount = $this->maxPoolsNum - $length;
                }
                if($this->callCount < 0) {
                	$this->callCount = 0;
                }
            });
		}
	}

    /**
     * @return mixed
     * @throws \Exception
     */
	public function fetchObj() {
		try {
			$this->callCount++;
			return $this->getObj();
		}catch(\Exception $e) {
			throw new \Exception($e->getMessage());
		}
	}

	/**
	 * getObj 开发者自行实现
	 * @return
	 */
	public abstract function getObj();
}