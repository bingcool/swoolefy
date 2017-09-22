<?php
namespace Swoolefy\Core;

class ZModel {
	/**
	 * $_instance 
	 * @var object
	 */
	protected static $_model_instances = [];

	/**
	 * getInstance
	 * @return   
	 */
	public static function getInstance($class) {
		$class = str_replace('/','\\',$class);
		$class = trim($class,'\\');
		if(isset(static::$_model_instances[$class]) && is_object(static::$_model_instances[$class])) {
            return static::$_model_instances[$class];
        }
		static::$_model_instances[$class] = new $class();
        return static::$_model_instances[$class];
	}
}