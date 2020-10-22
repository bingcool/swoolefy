<?php
/**
+----------------------------------------------------------------------
| swoolefy framework bases on swoole extension development, we can use it easily!
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| @see https://github.com/bingcool/swoolefy
+----------------------------------------------------------------------
 */

namespace Swoolefy\Core\Coroutine;

use ArrayObject;
use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;

class Context {
    /**
     * @return ArrayObject|null
     * @throws \Exception
     */
    public static function getContext() {
        if(\Swoole\Coroutine::getCid() > 0) {
            $context = \Swoole\Coroutine::getContext();
            return $context;
        }

        $app = Application::getApp();
        if(is_object($app)) {
            if($app->isSetContext()) {
                return $app->getContext();
            }else {
                $context = new ArrayObject();
                $context->setFlags(ArrayObject::STD_PROP_LIST|ArrayObject::ARRAY_AS_PROPS);
                $app->setContext($context);
                return $context;
            }
        }else if(Swfy::isUserProcess()) {
            throw new \Exception(__CLASS__."::getContext in UserProcess must use in App Instance");
        }

        return null;
    }

    /**
     * @param $name
     * @param $value
     * @throws \Exception
     */
    public static function set($name, $value) {
        $context = self::getContext();
        if($context) {
            $context[$name] = $value;
            return true;
        }
        return false;
    }

    /**
     * @param $name
     * @return boolean
     * @throws \Exception
     */
    public static function get($name) {
        $context = self::getContext();
        if($context) {
            return $context[$name];
        }
        return null;
    }

    /**
     * @param $name
     * @return boolean
     * @throws \Exception
     */
    public static function has($name) {
        $context = self::getContext();
        if($context) {
            if(isset($context[$name])) {
                return true;
            }
        }
        return false;
    }
}