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
 * 应用命名空间自动加载模板（create 时复制到 APP_PATH/Autoloader.php，并替换 "__APP_NAMESPACE__"）。
 *
 * 类名：\{AppName}\Autoloader（与业务根命名空间一致，避免多应用全局 class Autoloader 冲突）
 * 路径：{START_DIR_ROOT}/{AppName}/...
 * 可被多次 include。
 *
 * 注意：本文件仅作模板；占位符 __APP_NAMESPACE__ 在复制到应用目录时替换为真实应用名。
 */
namespace __APP_NAMESPACE__;

if (!class_exists(__NAMESPACE__ . '\\Autoloader', false)) {
    class Autoloader
    {
        /** @var string|null */
        private static $baseDirectory = null;

        /** @var list<string> */
        private static $rootNamespace = ['__APP_NAMESPACE__'];

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
                // 精确前缀：Foo 不匹配 Foobar\
                if ($className !== $namespace && !str_starts_with($className, $namespace . '\\')) {
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
