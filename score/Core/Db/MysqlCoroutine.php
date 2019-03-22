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

namespace Swoolefy\Core\Db;

class MysqlCoroutine {

	/**
	 * $master_mysql_config 主服务器的配置
	 * @var array
	 */
	public $master_mysql_config = [];
	public $master_swoole_mysql;

	/**
	 * $slave_mysql_config 从服务器的配置
	 * @var array
	 */
	public $slave_mysql_config = [];
	public $slave_swoole_mysql = [];

	/**
	 * $serverInfo mysql服务器的信息
	 * @var array
	 */
	public $serverInfo = []; 

	/**
	 * $config 配置
	 * @var [type]
	 */
	public $config = [
	    'host' => '',
	    'user' => '',
	    'password' => '',
	    'database' => '',
	    'port'    => '3306',
	    'timeout' => 5,
	    'charset' => 'utf8',
	    'strict_type' => false, //开启严格模式，返回的字段将自动转为数字类型
	    'fetch_more' => true, //开启fetch模式, 可与pdo一样使用fetch/fetchAll逐行或获取全部结果集(4.0版本以上)
	];

	/**
	 * $deploy 是否开启开启分布式，开启分布式则会自动读写分离
	 * @var boolean
	 */
	public $deploy = false;

    /**
     * MysqlCoroutine constructor.
     * @param array $config
     * @param array $extension
     */
	public function __construct(array $config = [], array $extension = []) {
		if($config) {
			$this->config['host'] = $config['hostname'];
			$this->config['user'] = $config['username'];
			$this->config['password'] = $config['password'];
			$this->config['database'] = $config['database'];
			$this->config['port'] = $config['hostport'];
			$this->config['charset'] = $config['charset'];

			isset($extension['timeout']) && $this->config['timeout'] = $extension['timeout'];
			isset($extension['strict_type']) && $this->config['strict_type'] = $extension['strict_type'];
			isset($extension['fetch_more']) && $this->config['fetch_more'] = $extension['fetch_more'];

			isset($config['deploy']) && $this->deploy = $config['deploy'];
			isset($config['rw_separate']) && $this->rw_separate = $config['rw_separate'];
		}

		$this->initConfig();

	}

	/**
	 * initConfig  初始化配置
	 * @return   
	 */
	public function initConfig() {
		$serverInfo = $this->parseConfig();
		foreach($serverInfo as $k=>$config) {
			// 主master
			if($k == 0) {
				if(!$this->deploy) {
					$this->master_mysql_config[$k] = $this->slave_mysql_config[$k] = $config;
				}else {
					$this->master_mysql_config[$k] = $config;
				}
						
			}else if($k) {
				$this->slave_mysql_config[$k] = $config;
			}
		}
	}

	/**execute 执行insert,update,delete操作
	 * @param    string        $sql
	 * @param    array         $bingParams
     * @param    int           $timeout
     * @throws   mixed
	 * @return   mixed
	 */
	public function execute(string $sql, array $bindParams = [], int $timeout = 10) {
		$keys = array_keys($bindParams);
		// 不是索引数组
		if(is_string($keys[0])) {
			$params = [];
			foreach($keys as $p=>$key) {
				$sql = str_replace(':'.$key, '?', $sql);
				array_push($params, $bindParams[$key]);
			}
		}
		if(stripos('=?', $sql) || stripos('= ?', $sql)) {
			$bindParams = $params;
			unset($params);
		}else {
			$bindParams = [];
		}

		$master = $this->getMaster();

		if($master->connected) {
			$res = $master->prepare($sql);
			if($res) {
				return $res->execute($bindParams, $timeout);
			}else {
				$errno = $master->errno;
				$error = $master->error;
				throw new \Exception("errno:{$errno},{$error}", 1);
			}
		}else {
			// 断线重连
			$configInfo = $master->configInfo;
			$isConnect = $master->connect($configInfo);
			if($isConnect) {
				$res = $master->prepare($sql);
				if($res) {
					return $res->execute($bindParams, $timeout);
				}else {
					$errno = $master->errno;
					$error = $master->error;
					throw new \Exception("errno:{$errno},{$error}", 1);
				}
			}else {
				throw new \Exception("master mysql:{$configInfo['host']}:{$configInfo['port']} connect is break,", 1);
			}
		}
		
	}

    /**
     * @param string   $sql
     * @param int|null $slave_num
     * @param int      $timeout
     * @return null
     * @throws \Exception
     */
	public function query(string $sql, int $slave_num = null, int $timeout = 10) {
		$slave = $this->getSlave($slave_num);
		if($slave->connected) {
			$result = $slave->query($sql, $timeout);
			if(is_array($result)) {
				return $result;
			}
			return null;
		}else {
			// 断线重连
			$configInfo = $slave->configInfo;
			$isConnect = $slave->connect($configInfo);
			if($isConnect) {
				$result = $slave->query($sql, $timeout);
				if(is_array($result)) {
					return $result;
				}
				return null;
			}else {
				throw new \Exception("slave mysql:{$configInfo['host']}:{$configInfo['port']} connect is break,", 1);
			}
		}
		
	}

    /**
     * getMaster 获取主服务器实例
     * @return \Swoole\Coroutine\MySQL
     * @throws \Exception
     */
	public function getMaster() {
		if(is_object($this->master_swoole_mysql)) {
			return $this->master_swoole_mysql;
		}else {	
			foreach($this->master_mysql_config as $k=>$config) {
				$swoole_mysql = new \Swoole\Coroutine\MySQL();
				$swoole_mysql->configInfo = $config;
				$isConnect = $swoole_mysql->connect($config);
				if($isConnect == false) {
					// 重连一次
					$isConnect = $swoole_mysql->connect($config);
					// 如果非分布式,那么读写都是同一个对象
					if($isConnect) {
						$this->master_swoole_mysql = $swoole_mysql;
					}else {
						throw new \Exception("master_swoole_mysql connect failed", 1);
					}
				}else {
					$this->master_swoole_mysql = $swoole_mysql;
				}
			}	
		}

		return $this->master_swoole_mysql;
	}

	/**
	 * getSlave 获取从服务实例
	 * @param    int|null  $num
     * @throws   \Exception
	 * @return   mixed
	 */
	public function getSlave(int $num = null) {
		// 非分布式，则主从一致
		if(!$this->deploy || empty($this->slave_mysql_config)) {
			return $this->getMaster();
		}
		// 分布式
		if(isset($this->slave_swoole_mysql[$num])) {
			return $this->slave_swoole_mysql[$num];
		}else {
			// 随机获取一个从服务器
			$num = array_rand($this->slave_mysql_config);
			// 判断是否已建立实例对象
			if(isset($this->slave_swoole_mysql[$num])) {
				return $this->slave_swoole_mysql[$num];
			}else {
				// 创建实例对象
				$config = $this->slave_mysql_config[$num];

				$swoole_mysql = new \Swoole\Coroutine\MySQL();
				$swoole_mysql->configInfo = $config;
				$isConnect = $swoole_mysql->connect($config);
				if($isConnect == false) {
					$isConnect = $swoole_mysql->connect($config);
					if($isConnect) {
						// 分布式
						$this->slave_swoole_mysql[$num] = $swoole_mysql;
					}else {
						throw new \Exception("slave_swoole_mysql connect failed", 1);	
					}
				}else {
					// 分布式
					$this->slave_swoole_mysql[$num] = $swoole_mysql;
				}

				return $this->slave_swoole_mysql[$num];
			} 
		}
	}

	/**
	 * getServerInfo 获取所有mysql服务器信息
	 * @return array
	 */
	public function getServerInfo() {
		return $this->serverInfo;
	}

	/**
	 * parseConfig  分析配置参数
	 * @return   array 
	 */
	public function parseConfig() {
		if(!empty($this->serverInfo)) {
			return $this->serverInfo;
		}

		$hosts = explode(',', $this->config['host']);
		$users = explode(',', $this->config['user']);
		$passwords = explode(',', $this->config['password']);
		$databases = explode(',', $this->config['database']);
		$ports = explode(',', $this->config['port']);
		// 主从架构
		if(count($hosts) > 1 && $this->deploy) {
			foreach($hosts as $k=>$host) {
				$serverInfo[$k]['host'] = $host;
				
				if(count($users) > 1) {
					$serverInfo[$k]['user'] = $users[$k];
				}else {
					$serverInfo[$k]['user'] = $users[0];
				}

				if(count($passwords) > 1) {
					$serverInfo[$k]['password'] = $passwords[$k];
				}else {
					$serverInfo[$k]['password'] = $passwords[0];
				}

				if(count($databases) > 1) {
					$serverInfo[$k]['database'] = $databases[$k];
				}else {
					$serverInfo[$k]['database'] = $databases[0];
				}

				if(count($ports) > 1) {
					$serverInfo[$k]['port'] = $ports[$k];
				}else {
					$serverInfo[$k]['port'] = $ports[0];
				}
			}

		}else {
			// 单机架构
			$k = 0;
			$serverInfo[$k]['host'] = $hosts[$k];
			$serverInfo[$k]['user'] = $users[$k];
			$serverInfo[$k]['password'] = $passwords[$k];
			$serverInfo[$k]['database'] = $databases[$k];
			$serverInfo[$k]['port'] = $ports[$k];
		}
		$this->serverInfo = $serverInfo;
		return $serverInfo;
	}

}