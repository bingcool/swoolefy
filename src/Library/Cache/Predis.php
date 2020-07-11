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

namespace Swoolefy\Library\Cache;

use Predis\Client;

/**
 * @see \Predis\Client
 * @mixin \Predis\Client
 */

class Predis extends RedisConnection {
	/**
	 * $predis redis实例
	 * @var \Predis\Client
	 */
	protected $redis;

	/**
	 * $parameters 
	 * @var null
	 */
	protected $parameters = null;

	/**
	 * $options 
	 * @var null
	*/
    protected $options = null;

    /**
     * $isInitConfig 是否已经初始化配置
     * @var boolean
     */
    protected $isInitConfig = false;

	/**
	 * __construct 
	 * @param  mixed  $parameters
	 * @param  mixed   $options
	 */
	public function __construct($parameters = null, $options = null) {
		if($parameters) {
			$this->redis = new \Predis\Client($parameters, $options);
			$this->parameters = $parameters;
			$this->options = $options;
			$this->isInitConfig = true;
		}
	}

	/**
	 * setConfig设置配置
	 * @param array $parameters
	 * @param array $options
	 * @param mixed
	 */
	public function setConfig($parameters = null, $options = null, ...$args) {
		if($this->isInitConfig) {
			return true;
		}
		if(is_object($this->redis)) {
			unset($this->redis);
		}
		$this->redis = new \Predis\Client($parameters, $options);
		$this->parameters = $parameters;
		$this->options = $options;
	}

	/**
	 * __call 重载
	 * @param  string  $method
	 * @param  array   $args
	 * @return mixed
	 */
	public function __call(string $method, array $arguments) {
		try{
            $this->log($method, $arguments,"start to exec method={$method}");
            $result = $this->redis->{$method}(...$arguments);
            $this->log($method, $arguments);
            return $result;
        }catch(\Exception $e) {
            $this->log($method, $arguments, $e->getMessage());
            $this->redis->disconnect();
            $this->log($method, $arguments, 'start to try again');
            $this->redis = new \Predis\Client($this->parameters, $this->options);
            $result = $this->redis->{$method}(...$arguments);
            $this->log($method, $arguments, 'retry ok');
            return $result;
        }catch (\Throwable $t) {
            $this->log($method, $arguments, 'retry failed,errorMsg='.$t->getMessage());
            throw $t;
        }
	}

    /**
     * getParameters 获取参数
     * @return array
     */
    public function getParameters() {
        return $this->parameters;
    }

    /**
	 * __destruct 销毁对象
	 */
	public function __destruct() {
        if(isset($this->parameters['persistent'])) {
            $persistent = filter_var($this->parameters['persistent'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if(!$persistent) {
                $this->redis->disconnect();
            }
        }
	}

}