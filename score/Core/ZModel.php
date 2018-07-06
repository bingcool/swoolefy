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

use  Swoolefy\Core\Coroutine\CoroutineManager;

class ZModel {
	/**
	 * $_instance 工厂模式的单实例
	 * @var array
	 */
	public static $_model_instances = [];

	/**
	 * getInstance 获取model的单例
	 * @param   string  $class  类命名空间
	 * @return  object 
	 */
	public static function getInstance(string $class = '') {
		$cid = CoroutineManager::getInstance()->getCoroutineId();
		$class = str_replace('/','\\', $class);
		$class = trim($class,'\\');
		if(isset(static::$_model_instances[$cid][$class]) && is_object(static::$_model_instances[$cid][$class])) {
            return static::$_model_instances[$cid][$class];
        }
		static::$_model_instances[$cid][$class] = new $class();
        return static::$_model_instances[$cid][$class];
	}

	/**
	 * removeInstance 删除某个协程下的model实例
	 * @return boolean
	 */
	public static function removeInstance() {
		$cid = CoroutineManager::getInstance()->getCoroutineId();
		if(isset(static::$_model_instances[$cid])) {
			return static::$_model_instances[$cid];
		}
		return true;
	}

}