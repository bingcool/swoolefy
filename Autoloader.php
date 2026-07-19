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

/**
 * 应用命名空间自动加载模板（create 时复制到 APP_PATH/Autoloader.php，并替换 &lt;{APP_NAME}&gt;）。
 *
 * 路径：{START_DIR_ROOT}/{AppName}/...
 * 可被多次 include。
 */
if (!class_exists('Autoloader', false) && !class_exists('autoloader', false)) {
    class Autoloader
    {
        /** @var string|null */
        private static $baseDirectory = null;

        /** @var list<string> */
        private static $rootNamespace = ["<{APP_NAME}>"];

        /** @var array<string, true> */
        private static $classMapNamespace = [];

        /** @var bool */
        private static $registered = false;

        /**
         * @param string $className
         */
        public static function autoload($className): void
        {
            if (isset(self::$classMapNamespace[$className])) {
                return;
            }

            if (self::$baseDirectory === null) {
                self::$baseDirectory = defined('START_DIR_ROOT')
                    ? START_DIR_ROOT
                    : dirname(__DIR__);
            }

            foreach (self::$rootNamespace as $namespace) {
                if (strpos($className, $namespace) !== 0) {
                    continue;
                }

                $parts = explode('\\', $className);
                $filepath = self::$baseDirectory
                    . DIRECTORY_SEPARATOR
                    . implode(DIRECTORY_SEPARATOR, $parts)
                    . '.php';

                if (is_file($filepath)) {
                    require_once $filepath;
                    self::$classMapNamespace[$className] = true;
                }

                break;
            }
        }

        public static function register($prepend = false): void
        {
            if (self::$registered) {
                return;
            }
            self::$registered = true;

            if (!function_exists('__autoload')) {
                spl_autoload_register([self::class, 'autoload'], true, $prepend);
            } else {
                trigger_error(
                    'spl_autoload_register() which will bypass your __autoload() and may break your autoloading',
                    E_USER_WARNING,
                );
            }
        }
    }

    Autoloader::register();
}

// cli.php 定义 APP_PATH 后再 include 本文件时加载业务常量
if (defined('APP_PATH') && is_file(APP_PATH . '/Config/constants.php')) {
    include_once APP_PATH . '/Config/constants.php';
}
