<?php

namespace Swoolefy\App;

use Swoolefy\Core\App;
use Swoolefy\Tool\ArrayHelper\ArrayForHelp;

// 上线必须设置为false
defined('SW_DEBUG') or define('SW_DEBUG', true);
defined('SW_ENV') or define('SW_ENV', 'dev');

class Application implements \Swoolefy\Core\AppInterface{
	// 初始化配置
	public static function init() {
		// 完成App应用层的命名空间的自动注册
		include(__DIR__.'/autoloader.php');
		
		include(__DIR__.'/../Core/Swfy.php');

		include(__DIR__."/Config/defines.php");

		// 加载App应用层配置和对应的协议配置
		$config = ArrayForHelp::merge(
			include(__DIR__.'/../Config/http.php'),
			include(__DIR__.'/Config/config.php')
		);

		return $config;
	}

	// 获取应用实例，完成各种配置以及初始化，不涉及具体业务
	public static function getInstance(array $config=[]) {
		$config = ArrayForHelp::merge(self::init(), $config);
		return new App($config);
	}
}

