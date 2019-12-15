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
	 * $_instance
	 * @var array
	 */
	protected static $_model_instances = [];

	/**
	 * getInstance 获取model的单例
	 * @param   string  $class  类命名空间
     * @param   array   $constructor 类构造函数参数
	 * @return  mixed
	 */
	public static function getInstance(string $class = '', array $constructor = []) {
		$cid = CoroutineManager::getInstance()->getCoroutineId();
		$class = self::parseClass($class);
		if(isset(static::$_model_instances[$cid][$class]) && is_object(static::$_model_instances[$cid][$class])) {
            return static::$_model_instances[$cid][$class];
        }
		static::$_model_instances[$cid][$class] = new $class(...$constructor);
        return static::$_model_instances[$cid][$class];
	}

	/**
	 * removeInstance 删除某个协程下的所有创建的model实例
     * @param int $cid
     * @param string $class
	 * @return boolean
	 */
	public static function removeInstance($cid = null, string $class = '') {
	    if(empty($cid)) {
            $cid = CoroutineManager::getInstance()->getCoroutineId();
        }
		if(isset(static::$_model_instances[$cid]) && empty($class)) {
			unset(static::$_model_instances[$cid]);
		}else if(isset(static::$_model_instances[$cid]) && !empty($class)) {
            $class = self::parseClass($class);
            if(isset(static::$_model_instances[$cid][$class])) {
                unset(static::$_model_instances[$cid][$class]);
            }
        }
		return true;
	}

    /**
     * @param string $class
     * @return mixed|string
     */
	private static function parseClass(string $class = '') {
        $class = str_replace('/','\\', $class);
        $class = trim($class,'\\');
        return $class;
    }

}