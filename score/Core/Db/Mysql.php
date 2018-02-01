<?php
namespace Swoolefy\Core\Db;

class Mysql {

	/**
	 * $instance 
	 * @var null
	 */
	protected static $instance = [];
	/**
	 * $Driver 
	 * @var null
	 */
	protected $Driver = null;

	/**
	 * $Query 
	 * @var null
	 */
	protected $Query = null;

	/**
	 * $driver_namespace 
	 * @var string
	 */
	protected $driverClass = '\\Swoolefy\\Core\\Db\\Connector\\';

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

	public function __construct(array $config=[]) {
		$this->config = array_merge($this->config, $config);
	}

	public function connect($name=false) {
		$options = $this->config;
		if(false === $name) {
            $name = md5(serialize($options));
        }

        if (true === $name || !isset(self::$instance[$name])) {
            if(empty($options['type'])) {
                throw new \Exception('Undefined db type');
            }

            $class = $this->driverClass.ucfirst($options['type']);

            if(true === $name) {
                $name = md5(serialize($options));
            }

            self::$instance[$name] = new $class($options);
        }

        return self::$instance[$name];
	}

    /**
	 * __call 自动聪载
	 * @param    string    $method
	 * @param    mixed   $params
	 * @return   mixed               
	 */
	public function __call($method, $params) {
		// 自动初始化数据库
        return call_user_func_array([$this->connect(), $method], $params);
	}
}