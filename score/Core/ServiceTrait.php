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

namespace Swoolefy\Core;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\BaseServer;

trait ServiceTrait {
	/**
	 * getMasterId 获取当前服务器主进程的PID
	 * @return   int
	 */
	public static function getMasterPid() {
		return Swfy::getServer()->master_pid;
	}

	/**
	 * getManagerId 获取当前服务器管理进程的PID
	 * @return   int
	 */
	public static function getManagerPid() {
		return Swfy::getServer()->manager_pid;
	}

	/**
	 * getCurrentWorkerPid 获取当前worker的进程PID 
	 * @return int  
	 */
	public static function getCurrentWorkerPid() {
		$workerPid = Swfy::getServer()->worker_pid;
		if($workerPid) {
			return $workerPid;
		}else {
			return posix_getpid();
		}
	}

	/**
	 * getCurrentWorkerId 获取当前处理的worker_id
	 * @return   int
	 */
	public static function getCurrentWorkerId() {
		$workerId = Swfy::getServer()->worker_id;
		return $workerId;
	}

	/**
	 * getConnections 获取服务器当前所有的连接
	 * @return  object 
	 */
	public static function getConnections() {
		return Swfy::getServer()->connections;
	}

	/**
	 * getWorkersPid 获取当前所有worker_pid与worker的映射
	 * @return   array
	 */
	public static function getWorkersPid() {
		return BaseServer::getWorkersPid();
	}

	/**
	 * getLastError 获取最近一次的错误代码
	 * @return   int 
	 */
	public static function getLastError() {
		return Swfy::getServer()->getLastError();
	}

	/**
	 * getStats 获取swoole的状态
	 * @return   array
	 */
	public static function getSwooleStats() {
		return Swfy::getServer()->stats();
	}

	/**
	 * getLocalIp 获取ip,不包括端口
	 * @return   array
	 */
	public static function getLocalIp() {
		return swoole_get_local_ip();
	}

	/**
	 * getIncludeFiles 获取swoole启动时,worker启动前已经include内存的文件
	 * @return   array|boolean
	 */
	public static function getInitIncludeFiles($dir='http') {
		// 获取当前的处理的worker_id
		$workerId = self::getCurrentWorkerId();
		if(isset(Swfy::$config['setting']['log_file'])) {
			$path = pathinfo(Swfy::$config['setting']['log_file'], PATHINFO_DIRNAME);
			$dir = strtolower($dir);
			$filePath = $path.'/includes.json';
		}else {
			$dir = ucfirst($dir);
			$filePath = __DIR__.'/../'.$dir.'/includes.json';
		}
		
		if(is_file($filePath)) {
			$includes_string = file_get_contents($filePath);
			if($includes_string) {
				return [
					'current_worker_id' => $workerId,
					'include_init_files' => json_decode($includes_string,true),
				];
			}else {
				return false;
			}
		}

		return false;
		
	}

	/**
	 * getMomeryIncludeFiles 获取执行到目前action为止，swoole server中的该worker中内存中已经加载的class文件
	 * @return  array 
	 */
	public static function getMomeryIncludeFiles() {
		$includeFiles = get_included_files();
		$workerId = self::getCurrentWorkerId();
		return [
			'current_worker_id' => $workerId,
			'include_momery_files' => $includeFiles,
		];
	}

	/**
	 * getConf 获取协议层对应的配置
	 * @param    $protocol
	 * @return   array
	 */
	public static function getConf() {
		return BaseServer::getConf();
	}

	/**
	 * getAppConfig 获取应用层配置
	 * @return   array
	 */
	public static function getAppConf() {
		return Swfy::$appConfig;
	}

	/**
	 * setAppConf 设置或重新设置原有的应用层配置
	 * @param    array         $config
	 * @return   boolean
	 */
	public static function setAppConf(array $config = []) {
		Swfy::$appConfig = $config;
		return true;
	}

	/**
	 * getSwooleSetting 获取swoole的setting配置
	 * @return   array
	 */
	public static function getSwooleSetting() {
		return BaseServer::getSetting();
	}

	/**
	 * isWorkerProcess 进程是否是worker进程
	 * @param    $worker_id
	 * @return   boolean
	 */
	public static function isWorkerProcess() {
		return (!self::isTaskProcess()) ? true : false;
	}

	/**
	 * isTaskProcess 进程是否是task进程
	 * @param    $worker_id
	 * @return   boolean
	 */
	public static function isTaskProcess() {
		$server = Swfy::getServer();
		if(property_exists($server, 'taskworker')) {
			return $server->taskworker;
		}
		throw new \Exception("not found task process,may be you use it before workerStart()", 1);
	}

	/**
	 * getServer 获取server对象
	 * @return   object
	 */
	public static function getServer() {
		if(is_object(Swfy::$server)) {
			return Swfy::$server;
		}else {
			return BaseServer::$server;
		}
	}
}