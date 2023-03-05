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
     * global conf
     * @var array
     */
    protected static $conf = [];

    /**
     * application conf
     * @var array
     */
    protected static $appConf = [];

    /**
     * @param object $server
     * @return bool
     */
    public static function setSwooleServer(object $server)
    {
        if (is_object($server)) {
            static::$server = $server;
            return true;
        }
        return false;
    }

    /**
     * @param array $conf
     * @param bool
     */
    public static function setConf(array $conf): bool
    {
        static::$conf = array_merge(static::$conf, $conf);
        return true;
    }

    /**
     * setAppConf
     * @param array $appConf
     * @return bool
     */
    public static function setAppConf(array $appConf = []): bool
    {
        static::$appConf = array_merge(static::$appConf, $appConf);
        return true;
    }

    /**
     * createComponent
     * @param string $com_alias_name
     * @param mixed $definition
     * @return mixed
     */
    public static function createComponent(?string $com_alias_name, $definition = [])
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
     * __call
     * @return void
     * @throws SystemException
     */
    public function __call($action, $args = [])
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
     * __callStatic
     * @return void
     * @throws SystemException
     */
    public static function __callStatic($action, $args = [])
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