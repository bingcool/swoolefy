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
            throw new SystemException(sprintf( "s%::s% in UserProcess must use in App Instance",
                __CLASS__,
                __FUNCTION__)
            );
        }

        return null;
    }

    /**
     * @param string $name
     * @param mixed $value
     * @return bool
     */
    public static function set(string $name, $value): bool
    {
        $context = self::getContext();
        $context[$name] = $value;
        return true;
    }

    /**
     * @param string $name
     * @return mixed
     */
    public static function get(string $name)
    {
        $context = self::getContext();
        return $context[$name];
    }

    /**
     * @param string $name
     * @return bool
     */
    public static function delete(string $name)
    {
        $context = self::getContext();
        unset($context[$name]);
        return true;
    }

    /**
     * @param string $name
     * @return bool
     */
    public static function has(string $name): bool
    {
        $context = self::getContext();
        if (isset($context[$name])) {
            return true;
        }
        return false;
    }
}