<?php
namespace Swoolefy\Core;

interface AppInterface {
	/**
	 * init 初始化配置
	 * @return   array
	 */
	public static function init();

	/**
	 * getInstance 获取应用对象实例
	 * @param    $config
	 * @return   object
	 */
	public static function getInstance(array $config);

	/**
	 * boostrap  完成一些必要的程序引导和设置
	 * @return   void
	 */
	public static function bootstrap();
}