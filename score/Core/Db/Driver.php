<?php
namespace Swoolefy\Core\Db;

use PDO;
use PDOStatement;

abstract class Driver {

	/**
	 * $PDOStatement 执行语句对象
	 * @var null
	 */
	protected $PDOStatement = null;

	/**
	 * $config 默认的配置值
	 * @var [type]
	 */
	public $config = [
		'master_host' => [],
		'slave_host' => [],
		'dbname' => '',
		'username' =>'',
		'password' =>'',
		'port' => 3306,
		'charset' => 'utf8',
		'deploy'  => 0, //是否启用分布式的主从
		'break_reconnect' => 1,//是否断线重连
	];

	/**
	 * $master_host
	 * @var array
	 */
	protected $master_host = null;
	/**
	 * $master_link 主服务器的连接池
	 * @var array
	 */
	protected $master_link = [];

	/**
	 * $_master_link_id 当前使用的主服务
	 * @var null
	 */
	protected $_master_link_pdo = null;

	/**
	 * $slave_host 
	 * @var array
	 */
	protected $slave_host = [];

	/**
	 * $slave_link 从服务器的连接池
	 * @var array
	 */
	protected $slave_link = [];

	/**
	 * $_slave_link_id 当前连接的从服务
	 * @var null
	 */
	protected $_slave_link_pdo = null;

	/**
	 * $params 参数对象
	 * @var [type]
	 */
    protected $params = [
        PDO::ATTR_CASE              => PDO::CASE_NATURAL,
        PDO::ATTR_ERRMODE           => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_ORACLE_NULLS      => PDO::NULL_NATURAL,
        PDO::ATTR_STRINGIFY_FETCHES => false,
        PDO::ATTR_EMULATE_PREPARES  => false,
        PDO::ATTR_PERSISTENT        => true
    ];

    /**
     * $attrCase
     * @var [type]
     */
   	protected $attrCase = PDO::CASE_LOWER;

   	/**
   	 * $queryStr 查询的sql字符串
   	 * @var null
   	 */
   	protected $queryStr = null;
   	/**
   	 * $bind
   	 * @var array
   	 */
    protected $bind = [];

   	/**
   	 * __construct 初始化函数
   	 * @param    $config
   	 */
	public function __construct($config=[]) {
		if(!empty($config)) {
            $this->config = array_merge($this->config, $config);
        }
	}

	/**
	 * connect
	 * @return  void
	 */
	public function connect() {
		// 创建主服务连接对象
       	$this->attrCase = $this->params[PDO::ATTR_CASE];

        try{
        	// 创建主服务连接对象
        	$master_dsn = $this->parseMasterDsn($this->config);
       		$this->master_link[0] = new PDO($master_dsn, $this->config['username'], $this->config['password'], $this->params);
       		// 如果设置分布式的主从模式，则创建从服务连接对象
        	if($this->isDeploy()) {
	        	$slave_dsn = $this->parseSlaveDsn($this->config);
	        	if(!empty($slave_dsn)) {
		        	foreach($slave_dsn as $k=>$dsn) {
		        		$this->slave_link[$k] = new PDO($dsn, $this->config['username'], $this->config['password'], $this->params);
		        	}
	        	}
        	}
        }catch (\PDOException $e) {
        	 dump($e->getMessage());
        }
        
        
	}

	/**
	 * parseDsn
	 * @return   string
	 */
	protected function parseMasterDsn($config) {
		$master_host = $this->getMasterHost();
		if(!isset($config['dsn'])) {
			$master_dsn = $config['type'].':dbname='.$config['dbname'].';host='.$master_host;
	        if(!empty($config['port'])) {
	            $master_dsn .= ';port='.$config['port'];
	        }else if(!empty($config['socket'])){
	            $master_dsn .= ';unix_socket='.$config['socket'];
	        }
	        $master_dsn .= ';charset='.$config['charset'];
	        return $master_dsn;
		}
	}

	/**
	 * parseSlaveDsn 
	 * @param    $config
	 * @return   array       
	 */
	protected function parseSlaveDsn($config) {
		$slave_host = $this->getSlaveHost();
		$slave_dsn = [];
		foreach ($slave_host as $k => $host) {
			$dsn = $config['type'].':dbname='.$config['dbname'].';host='.$host;
	        if(!empty($config['port'])) {
	            $dsn .= ';port='.$config['port'];
	        }else if(!empty($config['socket'])){
	            $dsn .= ';unix_socket='.$config['socket'];
	        }
	        $dsn .= ';charset='.$config['charset'];

	        $slave_dsn[] = $dsn;
		}

		return $slave_dsn;
	}

	/**
	 * isDeploy 是否启用主从分布式
	 * @return   boolean
	 */
	protected function isDeploy() {
		if(isset($this->config['deploy']) && $this->config['deploy'] == 1) {
			return true;
		}
		return false;
	}

	/**
	 * getMasterHost
	 * @return   string
	 */
	protected function getMasterHost() {
		if(is_array($this->config['master_host'])) {
			return $this->master_host = $this->config['master_host'][0];
		}else if(is_string($this->config['master_host'])){
			return $this->master_host = $this->config['master_host'];
		}
	}

	/**
	 * getSlaveHost
	 * @return   array
	 */
	protected function getSlaveHost() {
		if(is_array($this->config['slave_host'])) {
			return $this->slave_host = $this->config['slave_host'];
		}else if(is_string($this->config['slave_host'])) {
			return $this->slave_host = explode(',',$this->config['slave_host']);
		}
	}

	/**
	 * getMasterPdo
	 * @return   object
	 */
	public function getMasterPdo() {
		if(!empty($this->master_link)) {
			return $this->_master_link_pdo = $this->master_link[0];
		}else {
			return false;
		}
	}

	/**
	 * getSlavePdo
	 * @return   object
	 */
	public function getSlavePdo($slave_num=null) {
		if(!empty($this->slave_link)) {
			$count = count($this->slave_link);
			if(!$slave_num && is_int($slave_num) && $slave_num <= $count) {
				return $this->_slave_link_pdo = $this->slave_link[$slave_num-1];
			}else {
				return $this->_slave_link_pdo = mt_rand(0, $count-1);
			}
			
		}else {
			return false;
		}
	}

	/**
	 * getConfig
	 * @return   array
	 */
	public function getConfig($config = '') {
		return $config ? $this->config[$config] : $this->config;
	}

	 /**
	  * free 释放查询结果
	  * @return   void
	  */
    public function free() {
        $this->PDOStatement = null;
    }

    /**
     * fieldCase 对返数据表字段信息进行大小写转换
     * @param    $info
     * @return   array
     */
    public function fieldCase($info)
    {
        // 字段大小写转换
        switch ($this->attrCase) {
            case PDO::CASE_LOWER:
                $info = array_change_key_case($info);
                break;
            case PDO::CASE_UPPER:
                $info = array_change_key_case($info, CASE_UPPER);
                break;
            case PDO::CASE_NATURAL:
            default:
                // 不做转换
        }
        return $info;
    }

   	/**
   	 * query
   	 * @param    $sql
   	 * @param    $bind
   	 * @param    $master
   	 * @param    $pdo
   	 * @return   
   	 */
    public function query($sql, $bind = []) {
    	// 初始化连接
    	$this->initConnect();

        if(!$this->_master_link_pdo) {
            return false;
        }
        if(empty($this->slave_link) && !$this->_slave_link_pdo) {
        	return false;
        }

        // 记录SQL语句
        $this->queryStr = $sql;
        if($bind) {
            $this->bind = $bind;
        }

        // 释放前次的查询结果
        if (!empty($this->PDOStatement)) {
            $this->free();
        }
        try {
            // 调试开始
            // $this->debug(true);
            // 预处理
            if (empty($this->PDOStatement)) {
                $this->PDOStatement = $this->linkID->prepare($sql);
            }
            // 是否为存储过程调用
            $procedure = in_array(strtolower(substr(trim($sql), 0, 4)), ['call', 'exec']);
            // 参数绑定
            if ($procedure) {
                $this->bindParam($bind);
            } else {
                $this->bindValue($bind);
            }
            // 执行查询
            $this->PDOStatement->execute();
            // 调试结束
            // $this->debug(false);
            // 返回结果集
            return $this->getResult($pdo, $procedure);
        } catch (\PDOException $e) {
            if ($this->isBreak($e)) {
                return $this->close()->query($sql, $bind, $master, $pdo);
            }
            throw new PDOException($e, $this->config, $this->getLastsql());
        } catch (\Exception $e) {
            if ($this->isBreak($e)) {
                return $this->close()->query($sql, $bind, $master, $pdo);
            }
            throw $e;
        }
    }

    /**
     * isBreak 判断是否断线
     * @param \PDOException|\Exception  $e 异常对象
     * @return boolean
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

    protected function initConnect() {
    	$this->connect();
    	$this->getMasterPdo();
    	$this->getSlavePdo();
    	
    }


	

}