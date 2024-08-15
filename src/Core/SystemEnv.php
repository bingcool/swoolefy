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
use Swoolefy\Core\Log\LogManager;
use Swoolefy\Exception\SystemException;
use Symfony\Component\Console\Input\ArgvInput;

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
        return isScriptService();
    }

    /**
     * @return bool
     */
    public static function isCronService(): bool
    {
        return isCronService();
    }

    /**
     * @return string
     */
    public static function PhpBinFile(): string
    {
        return defined('PHP_BIN_FILE') ? constant('PHP_BIN_FILE') : '/usr/bin/php';
    }

    /**
     * @return array
     */
    public static function  inputOptions()
    {
        $options = [];
        $argv = new ArgvInput();
        $token = $argv->__toString();
        $items = explode(' ', $token);
        foreach ($items as $item) {
            if (str_starts_with($item, '--') || str_starts_with($item, '-')) {
                $item = trim($item,'-');
                $values = explode('=', $item, 2);
                $options[trim($values[0])] = trim($values[1]);
            }
        }
        return $options;
    }

    /**
     * @param string $name
     * @return array|string
     */
    public static function getOption(string $name, bool $force = false)
    {
        static $options;
        if ($force) {
            $options = self::inputOptions();
        }else {
            if (!isset($options)) {
                $options = self::inputOptions();
            }
        }
        $value = trim($options[$name],'\'') ?? '';
        $value = trim($value,' ');
        return $value;
    }

    /**
     * 此方法实时加载配置文件-磁盘IO，业务中建议使用Swfy::getConf()来读取配置
     *
     * @return array
     */
    public static function loadGlobalConf()
    {
        $path = APP_PATH.'/Protocol';
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
        $confFile = APP_PATH . '/Config/app.php';
        if (!file_exists($confFile)) {
            throw new SystemException("Not found app conf file:{$confFile}");
        }
        return include $confFile;
    }

    /**
     * 不同应用,logFile定义不同目录
     * @param string $logFile
     * @return string
     */
    public static function loadLogFile(string $logFile)
    {
        if (SystemEnv::isWorkerService()) {
            $path = pathinfo($logFile);
            return $path['dirname'] . '/' . WORKER_SERVICE_NAME . '/' . $path['filename'] . '_worker.' . $path['extension'];
        }
        return $logFile;
    }

    /**
     * 不同应用,pidFile定义不同目录
     *
     * @param string $pidFile
     * @return string
     */
    public static function loadPidFile(string $pidFile)
    {
        if (SystemEnv::isWorkerService()) {
            $path = pathinfo($pidFile);
            return $path['dirname'] . '/' . WORKER_SERVICE_NAME . '/' . $path['filename'] . '_worker.' . $path['extension'];
        }
        return $pidFile;
    }

    /**
     * 加载环境变量文件
     * @param string $env
     * @return array
     */
    public static function loadDcEnv(string $env = '')
    {
        if (empty($env)) {
            $dcFile = APP_PATH . '/Config/dc.php';
        }else {
            $dcFile = APP_PATH . '/Config/dc-' . $env . '.php';
        }

        if (file_exists($dcFile)) {
            return include APP_PATH . '/Config/dc.php';
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
        $componentDir = APP_PATH . '/Config/component';
        if (!is_dir($componentDir)) {
            $componentDir = APP_PATH . '/Config/Component';
            if (!is_dir($componentDir)) {
                return $components;
            }
        }

        $handle = opendir($componentDir);
        while ($file = readdir($handle)) {
            if($file == '.' || $file == '..' ) {
                continue;
            }
            $filePath = $componentDir.DIRECTORY_SEPARATOR.$file;
            $fileType = pathinfo($filePath, PATHINFO_EXTENSION);
            if (in_array($fileType, ['php'])) {
                $component = include $filePath;
                $intersectKeys = array_intersect_key($components, $component);
                if (!empty($intersectKeys)) {
                    $intersectNames      = array_keys($intersectKeys);
                    $intersectNameString = implode(',', $intersectNames);
                    throw new SystemException("Config Component 组件合并后数组key, 存在相同的组件名称[ {$intersectNameString} ], 互相覆盖");
                }
                $components = array_merge($components, $component);
            }
        }
        closedir($handle);
        return $components;
    }

    /**
     * @return void
     */
    public static function registerLogComponents(): array
    {
        // log register
        $logComponents = include CONFIG_COMPONENT_PATH.DIRECTORY_SEPARATOR.'log.php';
        foreach ($logComponents as $name=>$logFn) {
            if($logFn instanceof \Closure) {
                LogManager::getInstance()->registerLoggerByClosure($logFn, $name);
            }
        }
        return $logComponents;
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
     * @return void
     */
    public static function clearEnvRepository()
    {
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