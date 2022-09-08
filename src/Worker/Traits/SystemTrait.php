<?php
/**
 * +----------------------------------------------------------------------
 * | Daemon and Cli model about php process worker
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
 * +----------------------------------------------------------------------
 */

namespace Swoolefy\Worker\Traits;

use Swoolefy\Worker\Helper;

trait SystemTrait
{
    /**
     * @param bool $enableCoroutine
     * @return void
     */
    public function resetAsyncCoroutine(bool $enableCoroutine)
    {
        if (version_compare(swoole_version(), '4.6.0', '<')) {
            \Swoole\Timer::set([
                'enable_coroutine' => $enableCoroutine,
            ]);
        } else {
            /**
             * after swoole 4.6 Async AbstractEventHandle、Timer、Process::signal moveto Swoole\Async library
             */
            $isSetFlag = false;
            if (class_exists('Swoole\Async')) {
                \Swoole\Async::set([
                    'enable_coroutine' => $enableCoroutine,
                ]);
                $isSetFlag = true;
            }

            if (!$isSetFlag) {
                if (method_exists('Swoole\Timer', 'set')) {
                    @\Swoole\Timer::set([
                        'enable_coroutine' => $enableCoroutine,
                    ]);
                }
            }
        }
    }

    /**
     * getHookFlags
     * @param $hookFlags
     * @return int
     */
    protected function getHookFlags($hookFlags)
    {
        if (empty($hookFlags)) {
            if (version_compare(swoole_version(), '4.7.0', '>=')) {
                $hookFlags = SWOOLE_HOOK_ALL | SWOOLE_HOOK_NATIVE_CURL;
            } else if (version_compare(swoole_version(), '4.6.0', '>=')) {
                $hookFlags = SWOOLE_HOOK_ALL ^ SWOOLE_HOOK_CURL | SWOOLE_HOOK_NATIVE_CURL;
            } else {
                $hookFlags = SWOOLE_HOOK_ALL ^ SWOOLE_HOOK_CURL;
            }
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
    protected function inMasterProcessEnv()
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
    protected function inChildrenProcessEnv()
    {
        return !$this->inMasterProcessEnv();
    }

    /**
     * @param $msg
     * @param $foreground
     * @param $background
     * @return void
     */
    protected function writeInfo($msg, $foreground = "red", $background = 'black')
    {
        // Create new Colors class
        static $colors;
        if (!isset($colors)) {
            $colors = new \Swoolefy\Util\EachColor();
        }
        if($foreground == 'green') {
            $foreground = 'light_green';
        }
        $formatMsg = "--------------{$msg} --------------";
        echo $colors->getColoredString($formatMsg, $foreground, $background) . "\n\n";
        if (defined("CTL_LOG_FILE")) {
            if (defined('MAX_LOG_FILE_SIZE')) {
                $maxLogFileSize = MAX_LOG_FILE_SIZE;
            } else {
                $maxLogFileSize = 10 * 1024 * 1024;
            }
            if (is_file(WORKER_CTL_LOG_FILE) && filesize(WORKER_CTL_LOG_FILE) > $maxLogFileSize) {
                unlink(WORKER_CTL_LOG_FILE);
            }
            $logFd = fopen(WORKER_CTL_LOG_FILE, 'a+');
            $date  = date("Y-m-d H:i:s");
            $pid   = getmypid();
            $writeMsg = "【{$date}】【PID={$pid}】" . $msg . "\n";
            fwrite($logFd, $writeMsg);
            fclose($logFd);
        }
    }

    /**
     * @param string $name
     * @return array|false|string|null
     */
    protected function getCliParams(string $name = '')
    {
        return Helper::getCliParams($name);
    }

}
