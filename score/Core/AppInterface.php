<?php
namespace Swoolefy\Core;

interface AppInterface {
	/**
	 * init config
	 * @return   array
	 */
	static public function init();

	/**
	 * getInstance
	 * @param    $config
	 * @return   obj
	 */
	static public function getInstance(array $config);
} 