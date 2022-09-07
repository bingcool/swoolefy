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

}
