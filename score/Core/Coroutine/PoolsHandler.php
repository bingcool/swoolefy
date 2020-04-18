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

class PoolsHandler {

	protected $channel = null;

	protected $poolName;

	protected $poolsNum = 30;

	protected $pushTimeout = 2;

	protected $popTimeout = 1;

	protected $callCount = 0;

	protected $liveTime = 10;

	public function setPoolsNum(int $poolsNum = 50) {
		$this->poolsNum = $poolsNum;
	}

	public function getPoolsNum() {
		return $this->poolsNum;
	}

	public function setPushTimeout(float $pushTimeout = 3) {
	    $this->pushTimeout = $pushTimeout;
    }

    public function getPushTimeout() {
	    return $this->pushTimeout;
    }

    public function setPopTimeout(float $popTimeout = 1) {
	    $this->popTimeout = $popTimeout;
    }

    public function getPopTimeout() {
	    return $this->popTimeout;
    }

    public function setLiveTime(int $liveTime) {
	    $this->liveTime = $liveTime;
    }

    public function getLiveTime() {
	    return $this->liveTime;
    }

	public function getPoolName() {
		return $this->poolName;
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
     * @param string|null $poolName
     */
	public function registerPools(string $poolName = null) {
		if($poolName) {
			$this->poolName = trim($poolName);
			if(!isset($this->channel)) {
                $this->channel = new \Swoole\Coroutine\Channel($this->poolsNum);
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
                $isPush = true;
		        if(isset($obj->objExpireTime) && time() > $obj->objExpireTime) {
		            $isPush = false;
                }

                $length = $this->channel->length();
                if($length >= $this->poolsNum) {
                    $isPush = false;
                }

                if($isPush) {
                    $this->channel->push($obj, $this->pushTimeout);
                    $length = $this->channel->length();
                    // 矫正
                    if(($this->poolsNum - $length) == $this->callCount - 1) {
                        --$this->callCount;
                    }else {
                        $this->callCount = $this->poolsNum - $length;
                    }
                }else {
                    --$this->callCount;
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
			$obj = $this->getObj();
            is_object($obj) && $this->callCount++;
            return $obj;
		}catch(\Exception $e) {
			throw new \Exception($e->getMessage());
		}
	}

	/**
	 * getObj 开发者自行实现
	 * @return
	 */
    protected function getObj() {
        // 第一次开始创建对象
        if($this->callCount == 0 && $this->channel->isEmpty()) {
            if($this->poolsNum) {
                $this->build($this->poolsNum);
            }
            if($this->channel->length() > 0) {
                return $this->pop();
            }
        }else {
            if($this->callCount >= $this->poolsNum) {
                usleep(10 * 1000);
                $length = $this->channel->length();
                if($length > 0) {
                    return $this->pop();
                }else {
                    return null;
                }
            }else {
                // 是否已经调用了
                $length = $this->channel->length();
                if($length > 0) {
                    return $this->pop();
                }
            }
        }
    }

    /**
     * @param int $num
     * @param callable $callable
     * @throws Exception
     */
    protected function build(int $num, $callable = '') {
        if($callable instanceof \Closure) {
            $callFunction = $callable;
        }else {
            $callFunction = \Swoolefy\Core\Swfy::getAppConf()['components'][$this->poolName];
        }
        for($i=0; $i<$num; $i++) {
            $obj = call_user_func($callFunction, $this->poolName);
            if(!is_object($obj)) {
                throw new \Exception("components of {$this->poolName} must return object");
            }
            $obj->objExpireTime = time() + ($this->liveTime) + rand(1,10);
            $this->channel->push($obj, $this->pushTimeout);
        }
    }

    /**
     *
     */
    protected function pop() {
        $startTime = time();
        while($obj = $this->channel->pop($this->popTimeout)) {
            if(isset($obj->objExpireTime) && time() > $obj->objExpireTime) {
                //re build
                $this->build(1);
                if(time() - $startTime > 1) {
                    $isTimeOut = true;
                    break;
                }
            }else {
                break;
            }
        }

        if($obj === false || (isset($isTimeOut))) {
            return null;
        }

        return $obj;
    }
}