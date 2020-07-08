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

namespace Swoolefy\Library\Db;

class MysqlCoroutine {
    /**
     * $config 配置
     * @var array
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
     * $serverInfo mysql服务器的信息
     * @var array
     */
    protected $server_info = [];

    /**
	 * $master_mysql_config 主服务器的配置
	 * @var array
	 */
	protected $master_mysql_config = [];
	protected $master_swoole_mysql;

	/**
	 * $slave_mysql_config 从服务器的配置
	 * @var array
	 */
    protected $slave_mysql_config = [];
    protected $slave_swoole_mysql = [];

    /**
     * $deploy 是否开启开启分布式，开启分布式则会自动读写分离
     * @var boolean
     */
    protected $deploy = false;

    /**
     * MysqlCoroutine constructor.
     * @param array $config
     * @param array $extension
     */
	public function __construct(array $config = []) {
		if(!empty($config)) {
			$this->setConfig(array_merge($this->config, $config));
		}
	}

	/**
	 * initConfig  初始化配置
	 * @return   
	 */
	protected function initConfig() {
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

	/**
	 * setConfig
	 * @param array $config
	 */
	public function setConfig(array $config = []) {
		if($config) {
			$this->config['host'] = $config['host'];
			$this->config['user'] = $config['user'];
			$this->config['password'] = $config['password'];
			$this->config['database'] = $config['database'];
			$this->config['port'] = $config['port'];
			$this->config['charset'] = $config['charset'];

			isset($config['timeout']) && $this->config['timeout'] = $config['timeout'];
			isset($config['strict_type']) && $this->config['strict_type'] = $config['strict_type'];
			isset($config['fetch_more']) && $this->config['fetch_more'] = $config['fetch_more'];

			isset($config['deploy']) && $this->deploy = $config['deploy'];
			isset($config['rw_separate']) && $this->rw_separate = $config['rw_separate'];	
		}
		$this->initConfig();
		return $this;
	}

	/**execute 执行insert,update,delete操作
	 * @param  string  $sql
	 * @param  array   $bingParams
     * @param  int     $timeout
     * @throws mixed
	 * @return mixed
	 */
	public function execute(string $sql, array $bindParams = [], int $timeout = 10) {
		$keys = array_keys($bindParams);
		// 不是索引数组
		if(!empty($keys) && is_string($keys[0])) {
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
				throw new \Exception("MySQL Coroutine errno:{$errno},{$error}");
			}
		}else {
			//try to reconnect
			$configInfo = $master->serverInfo;
			$isConnect = $master->connect($master->serverInfo);
			if($isConnect) {
				$res = $master->prepare($sql);
				if($res) {
					return $res->execute($bindParams, $timeout);
				}else {
					$errno = $master->errno;
					$error = $master->error;
					throw new \Exception("MySQL Coroutine errno:{$errno},{$error}");
				}
			}else {
				throw new \Exception("MySQL Coroutine :{$configInfo['host']}:{$configInfo['port']} connect is break");
			}
		}
	}

    /**
     * @param string   $sql
     * @param int|null $slave_num
     * @param int      $timeout
     * @throws \Exception
     * @return mixed
     */
	public function query(string $sql, int $slave_num = null, int $timeout = 10) {
		$slave = $this->getSlave($slave_num);
		if($slave->connected) {
			$result = $slave->query($sql, $timeout);
		}else {
			$configInfo = $slave->serverInfo;
			$isConnect = $slave->connect($configInfo);
			if($isConnect) {
				$result = $slave->query($sql, $timeout);
			}else {
				throw new \Exception("Slave mysql:{$configInfo['host']}:{$configInfo['port']} connect is break");
			}
		}
		return $result;
	}

	/**
	 * connect
	 * @param  array       $config             
	 * @param  int|integer $retry_connect_times
     * @throws \Exception
	 * @return mixed
	 */
	protected function connect(array $config = [], int $retry_connect_times = 1) {
		do {
		    try {
                $swoole_mysql = new \Swoole\Coroutine\MySQL();
                $swoole_mysql->configInfo = $config;
                $isConnected = $swoole_mysql->connect($config);
                if($isConnected) {
                    break;
                }
                unset($swoole_mysql);
            }catch (\Throwable $throwable) {
                throw new \Exception($throwable->getMessage());
            }
		}while($retry_connect_times--);

		if($isConnected && is_object($swoole_mysql)) {
            return $swoole_mysql;
        }else {
        	$host = $config['host'];
            throw new \Exception("Swoole mysql connect failed to host:{$host}, please check config");
        }
	}

    /**
     * getMaster 获取主服务器实例
     * @throws \Exception
     * @return \Swoole\Coroutine\MySQL
     */
	public function getMaster() {
		if(!is_object($this->master_swoole_mysql)) {
            $configs = $this->master_mysql_config;
            $config = array_shift($configs);
            unset($configs);
            $master_mysql = $this->connect($config);
            if($master_mysql instanceof \Swoole\Coroutine\MySQL) {
                $this->master_swoole_mysql = $master_mysql;
            }
            unset($master_mysql);
		}
		return $this->master_swoole_mysql;
	}

	/**
	 * getSlave 获取从服务实例
	 * @param    int|null  $num
     * @throws   \Exception
	 * @return   \Swoole\Coroutine\MySQL
	 */
	public function getSlave(int $num = null) {
		// 非分布式，则主从一致
		if(!$this->deploy || empty($this->slave_mysql_config)) {
			return $this->getMaster();
		}
		if(isset($this->slave_swoole_mysql[$num])) {
			return $this->slave_swoole_mysql[$num];
		}

		if(is_array($this->slave_swoole_mysql) && !empty($this->slave_swoole_mysql)) {
			$num = array_rand($this->slave_swoole_mysql);
			$slave_mysql = $this->slave_swoole_mysql[$num];
			if($slave_mysql instanceof \Swoole\Coroutine\MySQL) {
				return $slave_mysql;
			}
			unset($slave_mysql);
		}

		// 随机建立实例
        $num = array_rand($this->slave_mysql_config);
        // 是否已建立实例对象
        if(isset($this->slave_swoole_mysql[$num])) {
            return $this->slave_swoole_mysql[$num];
        }

        $config = $this->slave_mysql_config[$num];
       	$slave_mysql = $this->connect($config);
       	if($slave_mysql instanceof \Swoole\Coroutine\MySQL) {
        	$this->slave_swoole_mysql[$num] = $slave_mysql;
        }
        return $this->slave_swoole_mysql[$num];

	}

	/**
	 * getServerInfo 获取所有mysql服务器信息
	 * @return array
	 */
	public function getServerInfo() {
		return $this->server_info;
	}

	/**
	 * parseConfig  分析配置参数
	 * @return   array 
	 */
	protected function parseConfig() {
		if(!empty($this->server_info)) {
			return $this->server_info;
		}
		if(is_string($this->config['host'])) {
			$hosts = explode(',', $this->config['host']);
		}else {
			$hosts = $this->config['host'];
		}
		if(is_string($this->config['user'])) {
			$users = explode(',', $this->config['user']);
		}else {
			$users = $this->config['user'];
		}
		if(is_string($this->config['password'])) {
			$passwords = explode(',', $this->config['password']);
		}else {
			$passwords = $this->config['password'];
		}
		if(is_string($this->config['database'])) {
			$databases = explode(',', $this->config['database']);
		}else {
			$databases = $this->config['database'];
		}
		if(is_string($this->config['port']) || is_int($this->config['port'])) {
			$ports = explode(',', $this->config['port']);
		}else {
			$ports = $this->config['port'];
		}
		// master-slave model
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

				$serverInfo[$k]['timeout'] = $this->config['timeout'];
				$serverInfo[$k]['charset'] = $this->config['charset'];
				$serverInfo[$k]['strict_type'] = $this->config['strict_type'];
				$serverInfo[$k]['fetch_more'] = $this->config['fetch_more'];
			}

		}else {
			// single model
			$k = 0;
			$serverInfo[$k]['host'] = $hosts[$k];
			$serverInfo[$k]['user'] = $users[$k];
			$serverInfo[$k]['password'] = $passwords[$k];
			$serverInfo[$k]['database'] = $databases[$k];
			$serverInfo[$k]['port'] = $ports[$k];
			$serverInfo[$k]['timeout'] = $this->config['timeout'];
			$serverInfo[$k]['charset'] = $this->config['charset'];
			$serverInfo[$k]['strict_type'] = $this->config['strict_type'];
			$serverInfo[$k]['fetch_more'] = $this->config['fetch_more'];
		}
		$this->server_info = $serverInfo;
		return $this->server_info;
	}

    /**
     * @param $name
     * @return mixed
     * @throws \Exception
     */
	public function __get($name) {
        return $this->getMaster()->$name;
    }

    /**
     * __call
     * @param string $method
     * @param array $args
     * @return mixed
     * @throws \Exception
     */
	public function __call(string $method, array $args = []) {
		$db = $this->getMaster();
		return $db->$method(...$args);
	}

}