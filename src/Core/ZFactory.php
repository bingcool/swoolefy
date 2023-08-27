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

use Swoolefy\Core\Coroutine\Context;

class ZFactory
{

    /**
     * $_instances
     * @var array
     */
    private static $_instances = [];

    /**
     * getInstance
     * @param string $class
     * @param array $constructor
     * @return mixed
     */
    public static function getInstance(string $class = '', array $constructor = [])
    {
        $cid = \Swoole\Coroutine::getCid();
        if($cid >= 0 && !Context::has(__CLASS__.'::'.__FUNCTION__)) {
            Context::set(__CLASS__.'::'.__FUNCTION__, 1);
            \Swoole\Coroutine\defer(function () use($cid) {
                self::removeInstance($cid);
            });
        }

        $class = self::parseClass($class);
        if (isset(static::$_instances[$cid][$class]) && is_object(static::$_instances[$cid][$class])) {
            return static::$_instances[$cid][$class];
        }

        static::$_instances[$cid][$class] = new $class(...$constructor);
        return static::$_instances[$cid][$class];
    }

    /**
     * newInstance
     * @param string $class
     * @param array $constructor
     * @return mixed
     */
    public static function newInstance(string $class = '', array $constructor = [])
    {
        return new $class(...$constructor);
    }

    /**
     * @param string $class
     * @param int|null $cid
     * @return bool
     */
    public static function removeInstance(string $class = '', ?int $cid = null, )
    {
        if (empty($cid)) {
            $cid = \Swoole\Coroutine::getCid();
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
     * @return string
     */
    private static function parseClass(string $class = ''): string
    {
        $class = str_replace('/', '\\', $class);
        $class = trim($class, '\\');
        return $class;
    }

}