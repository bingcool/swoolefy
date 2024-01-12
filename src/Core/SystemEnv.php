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

use PhpOption\Option;
use Dotenv\Repository\Adapter\PutenvAdapter;
use Dotenv\Repository\RepositoryBuilder;
use Swoolefy\Exception\SystemException;

class SystemEnv
{
    /**
     * Indicates if the putenv adapter is enabled.
     *
     * @var bool
     */
    protected static $putenv = false;

    /**
     * The environment repository instance.
     *
     * @var \Dotenv\Repository\RepositoryInterface|null
     */
    protected static $repository;

    /**
     * @return bool
     */
    public static function isDevEnv(): bool
    {
        if (SWOOLEFY_ENV == SWOOLEFY_DEV) {
            return true;
        }
        return false;
    }

    /**
     * @return bool
     */
    public static function isTestEnv(): bool
    {
        if (SWOOLEFY_ENV == SWOOLEFY_TEST) {
            return true;
        }
        return false;
    }

    /**
     * @return bool
     */
    public static function isGraEnv(): bool
    {
        if (SWOOLEFY_ENV == SWOOLEFY_GRA) {
            return true;
        }
        return false;
    }

    /**
     * @return bool
     */
    public static function isPrdEnv(): bool
    {
        if (SWOOLEFY_ENV == SWOOLEFY_PRD) {
            return true;
        }
        return false;
    }

    /**
     * @return bool
     */
    public static function isDaemon(): bool
    {
        return isDaemon();
    }

    /**
     * @return bool
     */
    public static function isWorkerService(): bool
    {
        return isWorkerService();
    }

    /**
     * @return bool
     */
    public static function isDaemonService(): bool
    {
        return isDaemonService();
    }

    /**
     * @return bool
     */
    public static function isScriptService(): bool
    {
        return isCliScript();
    }

    /**
     * @return bool
     */
    public static function isCronService(): bool
    {
        return isCronService();
    }

    /**
     * 此方法实时加载配置文件-磁盘IO，业务中建议使用Swfy::getConf()来读取配置
     *
     * @return array
     */
    public static function loadGlobalConf()
    {
        $path = START_DIR_ROOT . '/'.APP_NAME.'/Protocol';
        $conf = include $path .'/conf.php';
        return $conf;
    }

    /**
     * 此方法实时加载配置文件-磁盘IO，业务中建议使用Swfy::getAppConf()来读取配置
     *
     * @return array
     */
    public static function loadAppConf()
    {
        $confFile = START_DIR_ROOT . DIRECTORY_SEPARATOR . APP_NAME . '/Config/config.php';
        if (!file_exists($confFile)) {
            throw new SystemException("Not found app conf file:{$confFile}");
        }

        $constFile = START_DIR_ROOT . DIRECTORY_SEPARATOR . APP_NAME . '/Config/constants.php';

        if (!file_exists($confFile)) {
            throw new SystemException("Not found const file:{$constFile}");
        }

        include $constFile;

        return include $confFile;
    }

    /**
     * 加载环境变量文件
     * @param string $env
     * @return array
     */
    public static function loadDcEnv(string $env = '')
    {
        if (empty($env)) {
            $dcFile = START_DIR_ROOT . DIRECTORY_SEPARATOR . APP_NAME . '/Config/dc.php';
        }else {
            $dcFile = START_DIR_ROOT . DIRECTORY_SEPARATOR . APP_NAME . '/Config/dc-' . $env . '.php';
        }

        if (file_exists($dcFile)) {
            return include START_DIR_ROOT . DIRECTORY_SEPARATOR . APP_NAME . '/Config/dc.php';
        }else {
            return [];
        }
    }

    /**
     * 加载各个协程单例组件库
     *
     * @return array
     */
    public static function loadComponent()
    {
        $components = [];
        $componentDir = START_DIR_ROOT . DIRECTORY_SEPARATOR . APP_NAME . '/Config/component';
        if (!is_dir($componentDir)) {
            $componentDir = START_DIR_ROOT . DIRECTORY_SEPARATOR . APP_NAME . '/Config/Component';
            if (!is_dir($componentDir)) {
                return $components;
            }
        }

        $handle = opendir($componentDir);
        while ($file = readdir($handle)) {
            if($file == '.' || $file == '..' ){
                continue;
            }
            $filePath = $componentDir.DIRECTORY_SEPARATOR.$file;
            $fileType = pathinfo($filePath, PATHINFO_EXTENSION);
            if (in_array($fileType, ['php'])) {
                $component = include $filePath;
                $components = array_merge($components, $component);
            }
        }
        closedir($handle);
        return $components;
    }
    /**
     * Enable the putenv adapter.
     *
     * @return void
     */
    public static function enablePutenv()
    {
        static::$putenv = true;
        static::$repository = null;
    }

    /**
     * Disable the putenv adapter.
     *
     * @return void
     */
    public static function disablePutenv()
    {
        static::$putenv = false;
        static::$repository = null;
    }

    /**
     * Get the environment repository instance.
     *
     * @return \Dotenv\Repository\RepositoryInterface
     */
    public static function getEnvRepository()
    {
        if (static::$repository === null) {
            $builder = RepositoryBuilder::createWithDefaultAdapters();

            if (static::$putenv) {
                $builder = $builder->addAdapter(PutenvAdapter::class);
            }

            static::$repository = $builder->immutable()->make();
            $dotenv = \Dotenv\Dotenv::create(static::$repository, APP_PATH);
            $dotenv->safeload();
        }
        return static::$repository;
    }

    /**
     * Gets the value of an environment variable.
     *
     * @param  string  $key
     * @param  mixed  $default
     * @return mixed
     */
    public static function get($key, $default = null)
    {
        return Option::fromValue(static::getEnvRepository()->get($key))
            ->map(function ($value) {
                switch (strtolower($value)) {
                    case 'true':
                    case '(true)':
                        return true;
                    case 'false':
                    case '(false)':
                        return false;
                    case 'empty':
                    case '(empty)':
                        return '';
                    case 'null':
                    case '(null)':
                        return null;
                }

                if (preg_match('/\A([\'"])(.*)\1\z/', $value, $matches)) {
                    return $matches[2];
                }

                return $value;
            })
            ->getOrCall(function () use ($default) {
                return value($default);
            });
    }
}