<?php
namespace Swoolefy\Core;

class Base {

	/**
	 * $server
	 * @var null
	 */
	public static $server = null;

	/**
	 * $conf
	 * @var array
	 */
	public static $conf = [
		'reactor_num' => 1, //reactor thread num
		'worker_num' => 2,    //worker process num
		'max_request' => 1000,
		'daemonize' => 0
	];

	/**
	 * __construct
	 */
	public function __construct() {
		self::checkVersion();
	}
	/**
	 * setMasterProcessName设置主进程名称
	 */
	public static function setMasterProcessName($master_process_name) {

		swoole_set_process_name($master_process_name);
	}

	/**
	 * setManagerProcessName设置管理进程名称
	 */
	public static function setManagerProcessName($manager_process_name) {
		swoole_set_process_name($manager_process_name);
	}

	/**
	 * setWorkerProcessName设置worker进程名称
	 */
	public static function setWorkerProcessName($worker_process_name, $worker_id, $worker_num=1) {
		// 设置worker的进程
		if($worker_id >= $worker_num) {
            swoole_set_process_name($worker_process_name."-task".$worker_id);
        }else {
            swoole_set_process_name($worker_process_name."-worker".$worker_id);
        }

	}

	/**
	 * startInclude设置需要在workerstart启动时加载的配置文件
	 */
	public static function startInclude($includes) {
		foreach($includes as $filePath) {
			include_once $filePath;
		}
	}

	/**
	 * 设置worker进程的工作组，默认是root
	 */
	public static function setWorkerUserGroup($worker_user=null) {
		if(!isset(static::$conf['user'])) {
			if($worker_user) {
				$userInfo = posix_getpwnam($worker_user);
				if($userInfo) {
					posix_setuid($userInfo['uid']);
					posix_setgid($userInfo['gid']);
				}
			}
		}
	}

	/**
	 * 检查是否安装基础扩展
	 */
	public static function checkVersion() {
		if(version_compare(phpversion(), '5.6.0', '<')) {
			throw new \Exception("php version must be > 5.6.0,we suggest use php7.0+ version", 1);
		}

		if(!extension_loaded('swoole')) {
			throw new \Exception("you are not install swoole extentions,please install it where version >= 1.9.17 or >=2.0.5 from https://github.com/swoole/swoole-src", 1);
		}

		if(!extension_loaded('swoole_serialize')) {
			throw new \Exception("you are not install swoole_serialize extentions,please install it where from https://github.com/swoole/swoole_serialize", 1);
		}

		if(!extension_loaded('pcntl')) {
			throw new \Exception("you are not install pcntl extentions,please install it", 1);
		}

		if(!extension_loaded('posix')) {
			throw new \Exception("you are not install posix extentions,please install it", 1);
		}
	}

	/**
	 * restart
	 */
	public static function restart() {
		var_dump(self::$server);
	}

}