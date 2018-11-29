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
	 * @var [type]
	 */
	public $password;

	/**
	 * $selectDb 
	 * @var null
	 */
	public $selectdb = null;

	/**
	 * $is_serialize 是否开启序列化PHP变量，默认false
	 * @var boolean
	 */
	public $is_serialize = false;

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
	 * @param string       $host
	 * @param string       $port
	 * @param string|null  $password
     * @param int         $selectdb
     * @param bool        $deploy
	 * @param bool        $is_serialize
	 */
	public function __construct(string $host = null, $port = null, string $password = null, int $selectdb = null, bool $deploy = false, bool $is_serialize = false) {
		$host && $this->host  = $host;
		$port && $this->port = $port;
		$password && $this->password = $password;
		$selectdb && $this->selectdb = $selectdb;
		$deploy && $this->deploy = $deploy;
		$is_serialize && $this->is_serialize;
		$host && $this->setConfig();
	}

	/**
	 * setConfig 初始化设置,在配置func中设置该函数回调
	 * @param array
	 */
	public function setConfig() {
		$serverInfo = $this->parseConfig();
		foreach($serverInfo as $k=>$config) {
			// 主master
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
	 * setSelectDb 
	 */
	public function selectDb(int $db) {
		$this->selectdb = $db;
	}

	/**
	 * getMaster 获取主master
	 * @return coroutine_redis
	 */
	public function getMaster() {
		if(is_object($this->master_redis_hosts)) {
			return $this->master_redis_hosts;
		}
		foreach($this->master_redis_config as $k=>$config) {
			$config = array_values($config);
			list($host, $port, $password) = $config;
			$redis = new CRedis();
			$redis->connect($host, $port, $this->is_serialize);
			$redis->auth($password);
			$this->selectdb && $redis->select(intval($this->selectdb));
			$isConnected = $redis->connected;
			// 连接成功
			if($isConnected) {
				$this->master_redis_hosts = $redis;
			}else {
				// 断线重连一次
				unset($redis);
				$redis = new CRedis();
				$redis->connect($host, $port, $this->is_serialize);
				$redis->auth($password);
				$this->selectdb && $redis->select(intval($this->selectdb));
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
	 * @return coroutine_redis
	 */
	public function getSlave(int $num = null) {
		if(!$this->deploy || empty($this->slave_redis_config)) {
			return $this->getMaster();
		}

		if(isset($this->slave_redis_hosts[$num])) {
			return $this->slave_redis_hosts[$num];
		}else {
			// 随机取一个从服务器
			$num = array_rand($this->slave_redis_config);
			if(isset($this->slave_redis_hosts[$num])) {
				return $this->slave_redis_hosts[$num];
			}else {
				$config = $this->slave_redis_config[$num];
				$config = array_values($config);
				list($host, $port, $password) = $config;
				$redis = new CRedis();
				$redis->connect($host, $port, $this->is_serialize);
				$redis->auth($password);
				$this->selectdb && $redis->select(intval($this->selectdb));
				$isConnected = $redis->connected;
				if($isConnected) {
					$this->slave_redis_hosts[$num] = $redis;
				}else {
					// 断线重连一次
					unset($redis);
					$redis = new CRedis();
					$redis->connect($host, $port, $this->is_serialize);
					$redis->auth($password);
					$this->selectdb && $redis->select(intval($this->selectdb));
					$isConnected = $redis->connected;
					if($isConnected) {
						$this->slave_redis_hosts[$num] = $redis;
					}else {
						throw new \Exception("Slave Coroutine Redis client failed to redis server", 1);
					}
				}
			}
		}
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
		$hosts = explode(',', $this->host);
		$ports = explode(',', $this->port);
		$passwords = explode(',', $this->password);
		$serverInfo = [];
		// 主从|集群
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
			// 单机
			$k = 0;
			$serverInfo[$k]['host'] = $hosts[$k];
			$serverInfo[$k]['port'] = $ports[$k];
			$serverInfo[$k]['password'] = $passwords[$k];
		}

		$this->serverInfo = $serverInfo;

		return $serverInfo;
	}

	/**
	 * __call 单机模式下
	 * @param  string  $method
	 * @param  mixed   $args
	 * @return mixed
	 */
	public function __call(string $method, array $args) {
		$redis = $this->getMaster();
		return $redis->$method(...$args);
	}

}