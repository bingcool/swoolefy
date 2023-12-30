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

use Swoolefy\Exception\SystemException;

class Swfy
{

    use \Swoolefy\Core\ServiceTrait;

    /**
     * global swoole server
     * @var \Swoole\Server
     */
    protected static $server;

    /**
     * @param $server
     * @return bool
     */
    public static function setSwooleServer($server)
    {
        if (is_object($server)) {
            static::$server = $server;
            return true;
        }
        return false;
    }

    /**
     * createComponent
     * @param string $com_alias_name
     * @param \Closure $definition
     * @return mixed
     */
    public static function createComponent(?string $com_alias_name, \Closure $definition)
    {
        return Application::getApp()->creatObject($com_alias_name, $definition);
    }

    /**
     * removeComponent
     * @param string|array $com_alias_name
     * @param bool $isAll
     * @return bool
     */
    public static function removeComponent($com_alias_name, bool $isAll = false)
    {
        return Application::getApp()->clearComponent($com_alias_name, $isAll);
    }

    /**
     * getComponent
     * @param string $com_alias_name
     * @return mixed
     */
    public static function getComponent(?string $com_alias_name = null)
    {
        return Application::getApp()->getComponents($com_alias_name);
    }

    /**
     * @param string $action
     * @param array $args
     * @return mixed
     * @throws \Exception
     */
    public function __call(string $action, array $args = [])
    {
        // stop exec
        throw new SystemException(sprintf(
                "Calling unknown method: %s::%s",
                get_called_class(),
                $action
            )
        );
    }

    /**
     * @param string $action
     * @param array $args
     * @return mixed
     * @throws SystemException
     */
    public static function __callStatic(string $action, array $args = [])
    {
        // stop exec
        throw new SystemException(sprintf(
                "Calling unknown static method: %s::%s",
                get_called_class(),
                $action
            )
        );
    }

}