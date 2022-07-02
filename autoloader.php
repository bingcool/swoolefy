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

class autoloader {
    /**
     * $directory
     * @var string
     */
    private static $baseDirectory = START_DIR_ROOT;
   
    /**
     * Root Namespace
     * @var array
     */
    private static $rootNamespace = ["<{APP_NAME}>"];

    /**
     * Class Map Namespace
     * @var array
     */
    private static $classMapNamespace = [];

    /**
     * @param string $className 
     * @return void
     */
    public static function autoload($className) {
        if(isset(self::$classMapNamespace[$className])) {
            return;
        }
        foreach(self::$rootNamespace as $k=>$namespace) {
            if (0 === strpos($className, $namespace)) {
                $parts = explode('\\', $className);
                $filepath = self::$baseDirectory.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $parts).'.php';
                if (is_file($filepath)) {
                    $res = require_once $filepath;
                    if($res) {
                        self::$classMapNamespace[$className] = true;
                    }
                }
                break;
            }
        }
    }

    /**
     * register autoload
     */     
    public static function register($prepend=false) { 
        if(!function_exists('__autoload')) { 
            spl_autoload_register(array('autoloader', 'autoload'), true, $prepend);     
        }else {
            trigger_error('spl_autoload_register() which will bypass your __autoload() and may break your autoloading', E_USER_WARNING);
        }
    }
}

// include file
autoloader::register();

