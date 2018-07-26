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

use think\db\Query;
use Swoolefy\Core\Application;

class MysqlCoroutine {

	public $master_mysql_config = [];
	public $master_swoole_mysql;

	public $slave_mysql_config = [];
	public $slave_swoole_mysql = [];

	public $serverInfo = []; 

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

	public $deploy = false;

	public $rw_separate = false;


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
					$this->master_swoole_mysql[$k] = $config;
				}
						
			}else if($k) {
				$this->slave_mysql_config[$k] = $config;
			}
		}
	}

	/**execute
	 * execute 
	 * @param    string        $sql
	 * @param    array         $bingParams
	 * @return   void
	 */
	public function execute(string $sql, array $bingParams = []) {
		$keys = array_keys($bingParams);
		// 不是索引数组
		if(is_string($keys[0])) {
			$params = [];
			foreach($keys as $p=>$key) {
				$sql = str_replace(':'.$key, '?', $sql);
				$params[] = $bingParams[$key];
			}
			$bingParams = $params;
			unset($params);
		}

		$res = $this->getMaster()->prepare($sql);

		if($res) {
			return $res->execute($bingParams, 10);
		}else {
			$errno = $this->getMaster()->errno;
			$error = $this->getMaster()->error;
			throw new \Exception("errno:{$errno},{$error}", 1);
		}
	}

	/**
	 * query 从服务器查询
	 * @param    string     $sql
	 * @return   array
	 */
	public function query(string $sql, int $slave_num = null, $timeout = 10) {
		$result = $this->getSlave($slave_num)->query($sql, $timeout);
		if(is_array($result)) {
			return $result;
		}
		return null;
	}

	/**
	 * getMaster 获取主服务器实例
	 * @return  
	 */
	public function getMaster() {
		if(is_object($this->master_swoole_mysql)) {
			return $this->master_swoole_mysql;
		}else {
			foreach($this->master_mysql_config as $k=>$config) {
				$swoole_mysql = new \Swoole\Coroutine\MySQL();
				$res = $swoole_mysql->connect($config);
				if($res == false) {
					// 重连一次
					$res = $swoole_mysql->connect($config);
					// 如果非分布式,那么读写都是同一个对象
					if($res) {
						if(!$this->deploy) {
							$this->master_swoole_mysql = $swoole_mysql;
							array_push($this->slave_swoole_mysql, $swoole_mysql);
						}else {
							$this->master_swoole_mysql = $swoole_mysql;
						}
					}else {
						throw new \Exception("master_swoole_mysql connect failed", 1);
						
					}
				}else {
					if(!$this->deploy) {
						$this->master_swoole_mysql = $swoole_mysql;
						array_push($this->slave_swoole_mysql, $swoole_mysql);
					}else {
						$this->master_swoole_mysql = $swoole_mysql;
					}
				}
			}	
		}

		return $this->master_swoole_mysql;
	}

	/**
	 * getSlave 获取从服务实例
	 * @param    int|null  $num
	 * @return   swoole_mysql
	 */
	public function getSlave(int $num = null) {
		if(isset($this->slave_swoole_mysql[$num])) {
			return $this->slave_swoole_mysql[$num];
		}else {
			// 分布式
			// 随机获取一个从服务器
			$num = array_rand($this->slave_mysql_config);
			// 判断是否已建立实例对象
			if(isset($this->slave_swoole_mysql[$num])) {
				return $this->slave_swoole_mysql[$num];
			}else {
				// 创建实例对象
				$config = $this->slave_mysql_config[$num];

				$swoole_mysql = new \Swoole\Coroutine\MySQL();
				$res = $swoole_mysql->connect($config);
				if($res == false) {
					$res = $swoole_mysql->connect($config);
					if($res) {
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
			$serverInfo[$k]['host'] = $hosts[0];
			$serverInfo[$k]['user'] = $users[0];
			$serverInfo[$k]['password'] = $passwords[0];
			$serverInfo[$k]['database'] = $databases[0];
			$serverInfo[$k]['port'] = $ports[0];
		}

		return $serverInfo;
	}

	/**
     * 是否断线
     * @access protected
     * @param \PDOException|\Exception  $e 异常对象
     * @return bool
     */
    protected function isBreak($e)
    {
        if (!$this->config['break_reconnect']) {
            return false;
        }

        $info = [
            'server has gone away',
            'no connection to the server',
            'Lost connection',
            'is dead or not enabled',
            'Error while sending',
            'decryption failed or bad record mac',
            'server closed the connection unexpectedly',
            'SSL connection has been closed unexpectedly',
            'Error writing data to the connection',
            'Resource deadlock avoided',
        ];

        $error = $e->getMessage();

        foreach ($info as $msg) {
            if (false !== stripos($error, $msg)) {
                return true;
            }
        }
        return false;
    }

	
}