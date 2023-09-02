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

use Swoolefy\Exception\SystemException;

class SystemEnv
{
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
        return self::isDaemon();
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
     * @return array
     */
    public static function loadAppConf()
    {
        $confFile = START_DIR_ROOT . '/' . APP_NAME . '/Config/config.php';
        if (!file_exists($confFile)) {
            throw new SystemException("Not found app conf file:{$confFile}");
        }

        $constFile = START_DIR_ROOT . '/' . APP_NAME . '/Config/constants.php';

        if (!file_exists($confFile)) {
            throw new SystemException("Not found const file:{$constFile}");
        }

        include $constFile;

        return include $confFile;
    }

    /**
     * 加载环境变量文件
     *
     * @return array
     */
    public static function loadDcEnv()
    {
        $dcFile = START_DIR_ROOT . '/' . APP_NAME . '/Config/dc-' . SWOOLEFY_ENV . '.php';
        if (file_exists($dcFile)) {
            return include START_DIR_ROOT . '/' . APP_NAME . '/Config/dc-' . SWOOLEFY_ENV . '.php';
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
}