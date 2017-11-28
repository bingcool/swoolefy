<?php
namespace App;

class Application implements \Swoolefy\Core\AppInterface{
	// 初始化配置
	public static function init() {
		$runtime = __DIR__.'/Runtime';
		if(!is_dir($runtime)) {
			@mkdir($runtime,0777);
		}
		
		include(__DIR__."/Config/defines.php");

		// 加载App应用层配置和对应的协议配置
		$config = array_merge(
			include(__DIR__.'/../score/Config/common.php'),
			include(__DIR__.'/Config/config.php')
		);

		return $config;
	}

	// 获取应用实例，完成各种配置以及初始化，不涉及具体业务
	public static function getInstance(array $config=[]) {
		$config = array_merge(self::init(), $config);
		return new \Swoolefy\Core\App($config);
	}

	
	/**
	 * boostrap  完成程序的引导和环境变量的设置
	 * @return   
	 */
	public static function bootstrap() {
		if(function_exists('xhprof_enable')) {
			xhprof_enable(XHPROF_FLAGS_MEMORY);
		}
		// 上线必须设置为false
		defined('SW_DEBUG') or define('SW_DEBUG', true);
		defined('SW_ENV') or define('SW_ENV', 'dev');
	}
}

