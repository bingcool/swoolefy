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

namespace Swoolefy\Core\Coroutine;

use ArrayObject;
use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;
use Swoolefy\Exception\SystemException;

class Context
{

    /**
     * @return ArrayObject
     */
    public static function getContext()
    {
        if (\Swoole\Coroutine::getCid() > 0) {
            $context = \Swoole\Coroutine::getContext();
            return $context;
        }

        $app = Application::getApp();
        if (is_object($app)) {
            if ($app->isSetContext()) {
                return $app->getContext();
            } else {
                $context = new ArrayObject();
                $context->setFlags(ArrayObject::STD_PROP_LIST | ArrayObject::ARRAY_AS_PROPS);
                $app->setContext($context);
                return $context;
            }
        } else if (Swfy::isUserProcess()) {
            throw new SystemException(__CLASS__ . "::" . __FUNCTION__ . " in UserProcess must use in App Instance");
        }

        return null;
    }

    /**
     * @param string $name
     * @param mixed $value
     * @return bool
     */
    public static function set(string $name, $value)
    {
        $context = self::getContext();
        if ($context) {
            $context[$name] = $value;
            return true;
        }
        return false;
    }

    /**
     * @param string $name
     * @return bool
     */
    public static function get(string $name)
    {
        $context = self::getContext();
        if ($context) {
            return $context[$name];
        }
        return null;
    }

    /**
     * @param string $name
     * @return bool
     */
    public static function has(string $name)
    {
        $context = self::getContext();
        if ($context) {
            if (isset($context[$name])) {
                return true;
            }
        }
        return false;
    }
}