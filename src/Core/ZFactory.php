<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

namespace Swoolefy\Core;

use  Swoolefy\Core\Coroutine\CoroutineManager;

class ZFactory
{

    /**
     * $_instances
     * @var array
     */
    protected static $_instances = [];

    /**
     * getInstance
     * @param string $class
     * @param array $constructor
     * @return mixed
     */
    public static function getInstance(string $class = '', array $constructor = [])
    {
        $cid = CoroutineManager::getInstance()->getCoroutineId();
        $class = self::parseClass($class);
        if (isset(static::$_instances[$cid][$class]) && is_object(static::$_instances[$cid][$class])) {
            return static::$_instances[$cid][$class];
        }
        static::$_instances[$cid][$class] = new $class(...$constructor);
        return static::$_instances[$cid][$class];
    }

    /**
     * @param string $class
     * @param array $constructor
     * @return mixed
     */
    public static function newInstance(string $class = '', array $constructor = [])
    {
        return new $class(...$constructor);
    }

    /**
     * removeInstance
     * @param int $cid
     * @param string $class
     * @return bool
     */
    public static function removeInstance(?int$cid = null, string $class = '')
    {
        if (empty($cid)) {
            $cid = CoroutineManager::getInstance()->getCoroutineId();
        }
        if (isset(static::$_instances[$cid]) && empty($class)) {
            unset(static::$_instances[$cid]);
        } else if (isset(static::$_instances[$cid]) && !empty($class)) {
            $class = self::parseClass($class);
            if (isset(static::$_instances[$cid][$class])) {
                unset(static::$_instances[$cid][$class]);
            }
        }
        return true;
    }

    /**
     * @param string $class
     * @return mixed|string
     */
    private static function parseClass(string $class = '')
    {
        $class = str_replace('/', '\\', $class);
        $class = trim($class, '\\');
        return $class;
    }

}