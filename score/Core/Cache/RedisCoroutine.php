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

use \Swoole\Coroutine\Redis as CRedis;

class RedisCoroutine {
	/**
	 * $host 
	 * @var array
	 */
	public $host;

	/**
	 * $port 
	 * @var 
	 */
	public $port;

	/**
	 * $password 
	 * @var
	 */
	public $password;

	/**
	 * $is_serialize options
	 * @var array
	 */
	public $options = [];

	/**
	 * $is_deploy 是否是主从或者集群
	 * @var boolean
	 */
	public $deploy = false;

	/**
	 * $serverInfo 
	 * @var array
	 */
	protected $serverInfo = [];

	/**
	 * $master_redis_config redis主服务的配置
	 */
	protected $master_redis_config = [];

	/**
	 * $master_redis_host redis的主服务器实例
	 * @var array
	 */
	protected $master_redis_hosts;

	/**
	 * $slave_redis_config redis从服务器的配置
	 */
	protected $slave_redis_config = [];

	/**
	 * $slave_redis_host redis从服务器实例
	 * @var array
	 */
	protected $slave_redis_hosts = [];

	/**
	 * __construct
	 * @param mixed   $host
	 * @param mixed   $port
	 * @param mixed   $password
     * @param int     $selectdb
     * @param boolean $deploy
	 * @param array   $options
	 */
	public function __construct($host = null, $port = null, $password = null, bool $deploy = false, array $options = []) {
		if($host && $port) {
			$this->setHost($host);
			$this->setPort($port);
			$this->setPassword($password);
			$this->setDeploy($deploy);
			$this->setOptions($options);
			$this->setConfig();
		}
	}

	/**
	 * setHost 
	 * @param string|array $host
	 */
	public function setHost($host = null) {
		$host && $this->host = $host;
		return $this;
	}

	/**
	 * setPort
	 * @param  string|int|array $port
	 */
	public function setPort($port = null) {
		$port && $this->port = $port;
		return $this;
	}

	/**
	 * setPassword 
	 * @param string|array $password
     * @throws \Exception
     * @return mixed
	 */
	public function setPassword($password = null) {
		$password && $this->password = $password;
		if($this->host && $this->port) {
			$this->setConfig();
		}else {
			throw new \Exception("You must set Host And Port");
		}
		return $this;
	}

	/**
	 * setDeploy 主从模式需要设置这个为true
	 *  @param bool $is_deploy
	 */
	public function setDeploy(bool $is_deploy = false) {
        $is_deploy && $this->deploy = $is_deploy;
		return $this;
	}

	/**
	 * setOptions
	 * @param array $options
	 */
	public function setOptions(array $options = []) {
		!empty($options) && $this->options = $options;
		if(!isset($this->options['compatibility_mode'])) {
			$this->options['compatibility_mode'] = true;
		}
		return $this;
	}

	/**
	 * setConfig 初始化设置,在配置func中设置该函数回调
	 * @param array
	 */
	public function setConfig() {
		$serverInfo = $this->parseConfig();
		foreach($serverInfo as $k=>$config) {
			// master
			if($k == 0) {
				if(!$this->deploy) {
					$this->master_redis_config[$k] = $this->slave_redis_config[$k] = $config;
				}else {
					$this->master_redis_config[$k] = $config;
				}		
			}else if($k) {
				$this->slave_redis_config[$k] = $config;
			}
		}
		return $serverInfo;
	}

	/**
	 * getMaster 获取主master
     * @throws mixed
	 * @return mixed
	 */
	public function getMaster() {
		if(is_object($this->master_redis_hosts)) {
			return $this->master_redis_hosts;
		}

		foreach($this->master_redis_config as $k=>$config) {
			$config = array_values($config);
			list($host, $port, $password) = $config;
			$redis = new CRedis();
			if(!empty($this->options)) {
                $redis->setOptions($this->options);
            }
			$redis->connect($host, $port);
			$redis->auth($password);
			$isConnected = $redis->connected;

			if($isConnected) {
				$this->master_redis_hosts = $redis;
			}else {
				unset($redis);
				$redis = new CRedis();
				$redis->connect($host, $port);
                if(!empty($this->options)) {
                    $redis->setOptions($this->options);
                }
				$redis->auth($password);
				$isConnected = $redis->connected;
				if($isConnected) {
					$this->master_redis_hosts = $redis;
				}else {
					throw new \Exception("Master Coroutine Redis client failed to connect redis server", 1);
				}
			}
			break;
		}
		return $this->master_redis_hosts;
	}

	/**
	 * getSlave 获取从redis
	 * @param  int|null $num
     * @throws mixed
	 * @return mixed
	 */
	public function getSlave(int $num = null) {
		if(!$this->deploy || empty($this->slave_redis_config)) {
			return $this->getMaster();
		}

		if(isset($this->slave_redis_hosts[$num])) {
			return $this->slave_redis_hosts[$num];
		}

        // 随机取一个从服务器
        $num = array_rand($this->slave_redis_config);
        if(!isset($this->slave_redis_hosts[$num])) {
            $config = $this->slave_redis_config[$num];
            $config = array_values($config);
            list($host, $port, $password) = $config;
            $redis = new CRedis();
            $redis->connect($host, $port);
            if(!empty($this->options)) {
                $redis->setOptions($this->options);
            }
            $redis->auth($password);
            $isConnected = $redis->connected;

            if($isConnected) {
                $this->slave_redis_hosts[$num] = $redis;
            }else {
                unset($redis);
                $redis = new CRedis();
                $redis->connect($host, $port);
                if(!empty($this->options)) {
                    $redis->setOptions($this->options);
                }
                $redis->auth($password);
                $isConnected = $redis->connected;
                if($isConnected) {
                    $this->slave_redis_hosts[$num] = $redis;
                }else {
                    throw new \Exception("Slave Coroutine Redis client failed to redis server", 1);
                }
            }
        }
        return $this->slave_redis_hosts[$num];

	}

	/**
	 * getMasterConfig 获取主服务配置
	 * @return array
	 */
	public function getMasterConfig() {
		return $this->master_redis_config;
	}

	/**
	 * getSlaveConfig 获取从从服务配置
	 * @return array
	 */
	public function getSlaveConfig() {
		return $this->slave_redis_config;
	}

	/**
	 * parseConfig 分析配置
	 * @return 
	 */
	protected function parseConfig() {
		if(!empty($this->serverInfo)) {
			return $this->serverInfo;
		}
		if(is_string($this->host)) {
			$hosts = explode(',', $this->host);
		}else{
			$hosts = $this->host;
		} 
		if(is_string($this->port) || is_int($this->port)) {
			$ports = explode(',', $this->port);
		}else{
			$ports = $this->port;
		}
		if(is_string($this->password)) {
			$passwords = explode(',', $this->password);
		}else{
			$passwords = $this->password;
		}

		$serverInfo = [];
		// cluster mode
		if(count($hosts) > 1 && $this->is_deploy) {
			foreach($hosts as $k=>$host) {
				$serverInfo[$k]['host'] = $host;
				if(count($ports) > 1 ) {
					$serverInfo[$k]['port'] = $ports[$k];
				}else {
					$serverInfo[$k]['port'] = $ports[0];
				}

				if(count($passwords) > 1) {
					$serverInfo[$k]['password'] = $passwords[$k];
				}else {
					$serverInfo[$k]['password'] = $passwords[0];
				}
			}
		}else {
            //single pattern
			$k = 0;
			$serverInfo[$k]['host'] = $hosts[$k];
			$serverInfo[$k]['port'] = $ports[$k];
			$serverInfo[$k]['password'] = $passwords[$k];
		}

		$this->serverInfo = $serverInfo;

		return $serverInfo;
	}

	/**
	 * __call  single pattern,call master Instance
	 * @param  string  $method
	 * @param  array   $args
	 * @return mixed
	 */
	public function __call(string $method, array $args) {
		$redis = $this->getMaster();
		return $redis->$method(...$args);
	}

}