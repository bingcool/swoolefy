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
        $confFile = START_DIR_ROOT . '/' . APP_NAME . '/Config/config-' . SWOOLEFY_ENV . '.php';
        if (!file_exists($confFile)) {
            throw new SystemException("Not found app conf file:{$confFile}");
        }

        $constFile = START_DIR_ROOT . '/' . APP_NAME . '/Config/constants.php';

        if (!file_exists($confFile)) {
            throw new SystemException("Not found const file:{$constFile}");
        }

        include_once $constFile;

        return include_once $confFile;
    }

}