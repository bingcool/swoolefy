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

namespace Swoolefy\Worker\Traits;

use Swoolefy\Core\Swfy;
use Swoolefy\Worker\Helper;

trait SystemTrait
{
    /**
     * getHookFlags
     * @param $hookFlags
     * @return int
     */
    protected function getHookFlags($hookFlags)
    {
        if (empty($hookFlags)) {
            $hookFlags = Swfy::getConf()['setting']['hook_flags'] ?? SWOOLE_HOOK_ALL;
        }
        return $hookFlags;
    }

    /**
     * installErrorHandler
     *
     * @return void
     */
    protected function installErrorHandler()
    {
        set_error_handler(function ($errNo, $errStr, $errFile, $errLine) {
            switch ($errNo) {
                case E_ERROR:
                case E_PARSE:
                case E_CORE_ERROR:
                case E_COMPILE_ERROR:
                case E_USER_ERROR:
                    @ob_end_clean();
                    $errorStr = sprintf("%s in file %s on line %d",
                        $errStr,
                        $errFile,
                        $errLine
                    );
                    $exception = new \Exception($errorStr, $errNo);
                    $this->onHandleException($exception);
            }
        });
    }

    /**
     * @return bool
     */
    protected function inMasterProcessEnv(): bool
    {
        $pid = posix_getpid();
        if ((!defined('WORKER_MASTER_PID')) || (defined('WORKER_MASTER_PID') && $pid == WORKER_MASTER_PID)) {
            return true;
        }
        return false;
    }

    /**
     * @return bool
     */
    protected function inChildrenProcessEnv(): bool
    {
        return !$this->inMasterProcessEnv();
    }

    /**
     * 系统内部日志
     *
     * @param $msg
     * @return void
     */
    protected function fmtWriteInfo($msg)
    {
        initConsoleStyleIo()->info($msg);
        $this->writeLog($msg);
    }

    /**
     * 系统内部日志
     *
     * @param $msg
     * @return void
     */
    protected function fmtWriteNote($msg)
    {
        initConsoleStyleIo()->note($msg);
        $this->writeLog($msg);
    }

    /**
     * 系统内部日志
     *
     * @param $msg
     * @return void
     */
    protected function fmtWriteWarning($msg)
    {
        initConsoleStyleIo()->warning($msg);
        $this->writeLog($msg);
    }

    /**
     * 系统内部日志
     *
     * @param $msg
     * @return void
     */
    protected function fmtWriteError($msg)
    {
        initConsoleStyleIo()->error($msg);
        $this->writeLog($msg);
    }

    /**
     * @param string $msg
     * @return void
     */
    protected function writeLog(string $msg)
    {
        if (defined('WORKER_CTL_LOG_FILE')) {
            if (defined('MAX_LOG_FILE_SIZE')) {
                $maxLogFileSize = constant('MAX_LOG_FILE_SIZE');
            } else {
                $maxLogFileSize = 5 * 1024 * 1024;
            }

            if (is_file(WORKER_CTL_LOG_FILE) && filesize(WORKER_CTL_LOG_FILE) > $maxLogFileSize) {
                unlink(WORKER_CTL_LOG_FILE);
            }
            $logFd = fopen(WORKER_CTL_LOG_FILE, 'a+');
            $date  = date("Y-m-d H:i:s");
            $pid   = getmypid();
            $writeMsg = "【{$date}】【PID={$pid}】" . $msg . PHP_EOL;
            fwrite($logFd, $writeMsg);
            fclose($logFd);
        }
    }

    /**
     * @param string $name
     * @return array|false|string|null
     */
    protected function getOptionParams(string $name = '')
    {
        return Helper::getCliParams($name);
    }

}
