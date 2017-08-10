<?php
namespace Swoolefy\Core;

class Base {

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
	 * restart
	 */
	public static function restart() {
		
	}

}