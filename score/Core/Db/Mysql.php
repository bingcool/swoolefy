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

use Think\Db;
use Swoolefy\Core\Application;

class Mysql {

	/**
	 * $config 配置
	 * @var array
	 */
	public $config = [];

	/**
	 * $cacheHandler 缓存驱动处理
	 * @var null
	 */
	public $cache_driver = null;

	/**
	 * $default_config 默认的配置项
	 * @var [type]
	 */
	protected $default_config =  [
			// 数据库类型
		    'type'            => '',
		    // 服务器地址
		    'hostname'        => '',
		    // 数据库名
		    'database'        => '',
		    // 用户名
		    'username'        => '',
		    // 密码
		    'password'        => '',
		    // 端口
		    'hostport'        => '',
		    // 连接dsn
		    'dsn'             => '',
		    // 数据库连接参数
		    'params'          => [],
		    // 数据库编码默认采用utf8
		    'charset'         => 'utf8',
		    // 数据库表前缀
		    'prefix'          => '',
		    // 数据库调试模式
		    'debug'           => false,
		    // 数据库部署方式:0 集中式(单一服务器),1 分布式(主从服务器)
		    'deploy'          => 0,
		    // 数据库读写是否分离 主从式有效
		    'rw_separate'     => false,
		    // 读写分离后 主服务器数量
		    'master_num'      => 1,
		    // 指定从服务器序号
		    'slave_no'        => '',
		    // 是否严格检查字段是否存在
		    'fields_strict'   => true,
		    // 数据集返回类型
		    'resultset_type'  => '',
		    // 自动写入时间戳字段
		    'auto_timestamp'  => false,
		    // 时间字段取出后的默认时间格式
		    'datetime_format' => 'Y-m-d H:i:s',
		    // 是否需要进行SQL性能分析
		    'sql_explain'     => false,
		    // Builder类
		    'builder'         => '',
		    // Query类
		    'query'           => '\\think\\db\\Query',
		    // 是否需要断线重连
		    'break_reconnect' => true,
	];

	/**
	 * $query 查询对象
	 * @var null
	 */
	public $query = null;

	/**
	 * __construct 初始化函数
	 */
	public function __construct() {}

	/**
	 * getConfig 获取某个配置项
	 * @param  string   $name
	 * @return mixed
	 */
	public function getConfig($name = null) {
		return Db::getConfig($name = null);
	}

	/**
	 * setConfig 设置配置项
	 * @param array $config
	 */
	public function setConfig() {
		$db_config = $this->getConfig();
		if(empty($db_config['type']) && empty($db_config['hostname'])) {
			$config = array_merge($this->default_config, $this->config);
			Db::setConfig($config);
		}
	}

	/**
	 * setQuery 设置查询对象
	 * @param string   $query
	 */
	public function setQuery($query) {
		Db::setQuery($query);
	}

	/**
	 * setCacheHandler 设置缓存驱动
	 * @param $cacheHandler
	 */
	public function setCacheHandler($cache_driver = null) {
		if(is_object($cache_driver)) {
			Db::setCacheHandler($cache_driver);
		}

		$cacheHandler = $this->getCacheHandler();

		if(!is_object($cacheHandler)) {
			if(isset($this->cache_driver) && !empty($this->cache_driver)) {
				if(is_string($this->cache_driver) ) {
					$cache_driver = $this->cache_driver;
					$cache_driver = Application::getApp()->$cache_driver;

				}else if(is_object($this->cache_driver)){
					$cache_driver = $this->cache_driver;

				}else {
					$cache_driver = null;
				}
				Db::setCacheHandler($cache_driver);
			}
		}
		
	}

	/**
	 * getCacheHandler 
	 * @return 获取缓存驱动实例
	 */
	public function getCacheHandler() {
		return Db::getCacheHandler();
	}

	/**
	 * table 设置查询表
	 * @param  string   $table
	 * @return object
	 */
	public function table($table) {
		$this->setConfig();
		$this->setCacheHandler();
		$this->query = Db::table($table);
		return $this->query;
	}

	/**
	 * query sql查询类
	 * @param  string   $sql
	 * @param  array    $bind  
	 * @param  boolean  $master
	 * @param  boolean  $class 
	 * @return object      
	 */
	public function query($sql, $bind = [], $master = false, $class = false) {
		$this->setConfig();
		$this->setCacheHandler();
		return Db::query($sql, $bind , $master, $class);
	}

	/**
	 * execute sql插入数据
	 * @param  string   $sql 
	 * @param  array    $bind
	 * @return object      
	 */
	public function execute($sql, $bind = []) {
		$this->setConfig();
		$this->setCacheHandler();
		return Db::execute($sql, $bind);
	}

	/**
	 * connect 创建一个新的数据连接实例
	 * @param  array    $config
	 * @param  boolean  $name  
	 * @return object         
	 */
	public function connect($config = [], $name = false) {
		return Db::connect($config, $name);
	}

	/**
	 * __call 
	 * @param  string  $method
	 * @param  mixed   $args  
	 * @return mixed        
	 */
	public function __call($method, $args) {
		$this->setConfig();
		$this->setCacheHandler();
		// set hook call
		Application::getApp()->afterRequest([$this,'clear']);
		return Db::$method($args);
	}

	/**
	 * clear 清空静态变量
	 * @return void
	 */
	public function clear() {
		Db::$queryTimes = 0;
		Db::$executeTimes = 0;
		Db::clear();
	}

}