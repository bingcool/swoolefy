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
	 * $fetchType 查询结果后返回的数据类型
	 * @var [type]
	 */
	protected $fetchType = PDO::FETCH_ASSOC;

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
        	$e->getMessage();
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
		if(isset($this->config['deploy']) && ($this->config['deploy'] == 1 || $this->config['deploy'] == true)) {
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
			$this->_master_link_pdo = $this->master_link[0];
			return $this->_master_link_pdo;
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
				$this->_slave_link_pdo = $this->slave_link[$slave_num-1];
				return $this->_slave_link_pdo;
			}else {
				$this->_slave_link_pdo = mt_rand(0, $count-1);

				return $this->_slave_link_pdo;
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
		return $this->config;
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
    public function query($sql, $bind = [], $master = false, $pdo = false) {
    	// 初始化连接
    	$this->initConnect();

        if(!$this->_master_link_pdo) {
            return false;
        }

        if($this->isDeploy()) {
        	if(empty($this->slave_link) && !$this->_slave_link_pdo) {
        		throw new \Exception('If DB for mysql set isDeploy = 1 or true, so slave_host must be set!');
        	}
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
            if(empty($this->PDOStatement)) {
                $this->PDOStatement = $this->_master_link_pdo->prepare($sql);
            }
            // 是否为存储过程调用
            $procedure = in_array(strtolower(substr(trim($sql), 0, 4)), ['call', 'exec']);
            // 参数绑定
            if ($procedure) {
                $this->bindParam($bind);
            }else {
                $this->bindValue($bind);
            }
            // 执行查询
            $this->PDOStatement->execute();
            // 调试结束
            // $this->debug(false);
            // 返回结果集
            return $this->getResult($pdo, $procedure);

        }catch (\PDOException $e) {
        	// 如果断线，尝试重连
            if($this->isBreak($e)) {

            }
            throw new \PDOException($e);
        }catch (\Exception $e) {
        	// 如果断线，尝试重连
            if($this->isBreak($e)) {

            }
            throw $e;
        }
    }

    /**
     * 获得数据集数组
     * @param bool   $pdo 是否返回PDOStatement
     * @param bool   $procedure 是否存储过程
     * @return PDOStatement|array
     */
    protected function getResult($pdo = false, $procedure = false) {
        if($pdo) {
            // 返回PDOStatement对象处理
            return $this->PDOStatement;
        }
        if($procedure) {
            // 存储过程返回结果
            return $this->procedure();
        }
        $result  = $this->PDOStatement->fetchAll($this->fetchType);
        $this->numRows = count($result);
        return $result;
    }

    /**
     * 获取最近一次查询的sql语句
     * @return string
     */
    public function getLastSql()
    {
        return $this->getRealSql($this->queryStr, $this->bind);
    }

    /**
     * 根据参数绑定组装最终的SQL语句 便于调试
     * @param string    $sql 带参数绑定的sql语句
     * @param array     $bind 参数绑定列表
     * @return string
     */
    public function getRealSql($sql, array $bind = [])
    {
        foreach ($bind as $key => $val) {
            $value = is_array($val) ? $val[0] : $val;
            $type  = is_array($val) ? $val[1] : PDO::PARAM_STR;
            if (PDO::PARAM_STR == $type) {
                $value = $this->quote($value);
            } elseif (PDO::PARAM_INT == $type) {
                $value = (float) $value;
            }
            // 判断占位符
            $sql = is_numeric($key) ?
            substr_replace($sql, $value, strpos($sql, '?'), 1) :
            str_replace(
                [':' . $key . ')', ':' . $key . ',', ':' . $key . ' '],
                [$value . ')', $value . ',', $value . ' '],
                $sql . ' ');
        }
        return rtrim($sql);
    }

    /**
     * 参数绑定
     * 支持 ['name'=>'value','id'=>123] 对应命名占位符
     * 或者 ['value',123] 对应问号占位符
     * @param array $bind 要绑定的参数列表
     * @return void
     * @throws BindParamException
     */
    protected function bindValue(array $bind = [])
    {
        foreach ($bind as $key => $val) {
            // 占位符
            $param = is_numeric($key) ? $key + 1 : ':' . $key;
            if (is_array($val)) {
                if (PDO::PARAM_INT == $val[1] && '' === $val[0]) {
                    $val[0] = 0;
                }
                $result = $this->PDOStatement->bindValue($param, $val[0], $val[1]);
            } else {
                $result = $this->PDOStatement->bindValue($param, $val);
            }
            if (!$result) {
                throw new \Exception("Error occurred  when binding parameters '{$param}':".$this->getLastsql());
            }
        }
    }

    /**
     * 存储过程的输入输出参数绑定
     * @param array $bind 要绑定的参数列表
     * @return void
     * @throws BindParamException
     */
    protected function bindParam($bind)
    {
        foreach ($bind as $key => $val) {
            $param = is_numeric($key) ? $key + 1 : ':' . $key;
            if (is_array($val)) {
                array_unshift($val, $param);
                $result = call_user_func_array([$this->PDOStatement, 'bindParam'], $val);
            } else {
                $result = $this->PDOStatement->bindValue($param, $val);
            }
            if (!$result) {
                $param = array_shift($val);
                throw new \Exception("Error occurred  when binding parameters '{$param}':".$this->getLastsql());
            }
        }
    }

    /**
     * isBreak 判断是否断线
     * @param \PDOException|\Exception  $e 异常对象
     * @return boolean
     */
    protected function isBreak($e) {
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

    public function initConnect() {
    	$this->connect();
    	$this->getMasterPdo();
    	$this->getSlavePdo();
  
    }

    /**
     * 调用Query类的查询方法
     * @param string    $method 方法名称
     * @param array     $args 调用参数
     * @return mixed
     */
    public function __call($method, $args)
    {
        return call_user_func_array([$this->getQuery(), $method], $args);
    }

    /**
     * 获取新的查询对象
     * @return Query
     */
    protected function getQuery()
    {
        $class = '\\Swoolefy\\Core\\Db\\Query';
        return new $class($this);
    }

    /**
     * 获取当前连接器类对应的Builder类
     * @return string
     */
    public function getBuilder()
    {
        
    }

    /**
     * getFields 取得数据表的字段信息
     * @param string $tableName
     * @return array
     */
    public function getFields($tableName) {
        if (false === strpos($tableName, '`')) {
            $tableName = '`' . $tableName . '`';
        }
        $sql    = 'SHOW COLUMNS FROM ' . $tableName;
        $pdo    = $this->query($sql, [], false, true);
        $result = $pdo->fetchAll(PDO::FETCH_ASSOC);
        $info   = [];
        if ($result) {
            foreach ($result as $key => $val) {
                $val  = array_change_key_case($val);
                $info[$val['field']] = [
                    'name'    => $val['field'],
                    'type'    => $val['type'],
                    'notnull' => (bool) ('' === $val['null']), // not null is empty, null is yes
                    'default' => $val['default'],
                    'primary' => (strtolower($val['key']) == 'pri'),
                    'autoinc' => (strtolower($val['extra']) == 'auto_increment'),
                ];
            }
        }
        return $this->fieldCase($info);
    }


	

}