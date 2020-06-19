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
        if(\Co::getCid() > 0) {
            $context = \Co::getContext();
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
     * @return bool
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
     * @return bool
     * @throws \Exception
     */
    public static function has($name) {
        $context = self::getContext();
        if($context) {
            if(isset($context[$name])) {
                return true;
            }
            return false;
        }
        return false;
    }
}