<?php
namespace Swoolefy\Core;

interface AppInterface {
	/**
	 * init config
	 * @return   array
	 */
	public static function init();

	/**
	 * getInstance
	 * @param    $config
	 * @return   object
	 */
	public static function getInstance(array $config);
} 