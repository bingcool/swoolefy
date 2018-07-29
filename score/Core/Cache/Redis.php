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

namespace Swoolefy\Core\Cache;

use  Predis\Client;

class Redis {
    
	/**
	 * $Predis redis实例
	 * @var [type]
	 */
	protected $Predis;

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
	 * __construct 
	 * @param  mixed  $parameters
	 * @param  ixed   $options
	 */
	public function __construct($parameters = null, $options = null) {
		if($parameters) {
			$this->Predis = new \Predis\Client($parameters, $options);
			$this->parameters = $parameters;
			$this->options = $options;
		}
	}

	/**
	 * setConfig设置配置
	 * @param array $parameters
	 * @param array $options
	 * @param mixed
	 */
	public function setConfig($parameters = null, $options = null, ...$args) {
		if(is_object($this->Predis)) {
			unset($this->Predis);
		}
		$this->Predis = new \Predis\Client($parameters, $options);
		$this->parameters = $parameters;
		$this->options = $options;
	}

	/**
	 * __call 重载
	 * @param  string  $method
	 * @param  mixed   $args
	 * @return mixed
	 */
	public function __call(string $method, array $args) {
		// 断线
		if(!$this->Predis->isConnected()) {
			// 销毁redis实例
			unset($this->Predis);
			// 重建redis实例
			$this->Predis = new \Predis\Client($this->parameters, $this->options);
		}
		return call_user_func_array([$this->Predis, $method], $args);
	}

	/**
	 * __destruct 销毁对象
	 */
	public function __destruct() {
		// 断开并销毁redis的socket
		$this->Predis->disconnect();
	}

	/**
	 * getParameters 获取参数
	 * @return array
	 */
	public function getParameters() {
		return $this->parameters;
	}	
}