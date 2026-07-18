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
 * Test\ Demo 应用 PSR-0 风格加载：{START_DIR_ROOT}/Test/...
 *
 * 由 cli.php → registerNamespace(APP_PATH) 或 PHPUnit bootstrap 引入。
 * START_DIR_ROOT 须为仓库根（与 cli.php 一致），不是 Test/ 目录本身。
 */
class autoloader
{
    /** @var string */
    private static $baseDirectory = null;

    /** @var list<string> */
    private static $rootNamespace = ['Test'];

    /** @var array<string, true> */
    private static $classMapNamespace = [];

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

autoloader::register();

// PHPUnit bootstrap 只注册命名空间；cli.php start Test 仍加载业务常量
if (!defined('SWOOLEFY_PHPUNIT_BOOTSTRAP')
    && defined('APP_PATH')
    && is_file(APP_PATH . '/Config/constants.php')
) {
    include APP_PATH . '/Config/constants.php';
}
