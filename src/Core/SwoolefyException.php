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

use Swoolefy\Core\Log\LogManager;

class SwoolefyException
{

    const EXCEPTION_ERR = 'error';

    const EXCEPTION_WARNING = 'warning';

    const EXCEPTION_NOTICE = 'notice';

    const EXCEPTION_INFO = 'info';

    /**
     * fatalError 致命错误捕获,两种情况触发
     * a)代码中执行exit(),die()原生函数，在swoole中是禁止使用这个两个函数的，因为会导致worker退出
     * b)代码中发生异常，throw
     * c)代码执行完毕，由于在这里是worker常驻内存的，register_shutdown_function所注册是在worker进程中的，所以代码执行完毕不会触发，在php-fpm中代码会执行
     * @return void
     */
    public static function fatalError()
    {
        if ($error = error_get_last()) {
            switch ($error['type']) {
                case E_ERROR:
                case E_PARSE:
                case E_CORE_ERROR:
                case E_COMPILE_ERROR:
                case E_USER_ERROR:
                    @ob_end_clean();
                    $errorStr = sprintf("%s in file %s on line %d",
                        $error['message'],
                        $error["file"],
                        $error['line']
                    );
                    $throwable = new \Exception($errorStr);
                    static::shutHalt($errorStr, SwoolefyException::EXCEPTION_ERR, $throwable);
                    break;
            }
        }
    }

    /**
     * appException 自定义异常处理
     * @param \Throwable $exception 异常对象
     */
    public static function appException($exception)
    {
        $error['message'] = $exception->getMessage();
        $trace = $exception->getTrace();
        if ('E' == $trace[0]['function']) {
            $error['file'] = $trace[0]['file'];
            $error['line'] = $trace[0]['line'];
        } else {
            $error['file'] = $exception->getFile();
            $error['line'] = $exception->getLine();
        }
        $error['trace'] = $exception->getTraceAsString();
        $errorStr = sprintf(
            "%s in file %s on line %d",
            $error['message'],
            $error["file"],
            $error['line']
        );

        static::shutHalt($errorStr, SwoolefyException::EXCEPTION_ERR, $exception);
    }

    /**
     * appError 获取用户程序错误
     * @param int $errorNo
     * @param string $errorString
     * @param string $errorFile
     * @param int $errorLine
     * @return void
     */
    public static function appError($errorNo, $errorString, $errorFile, $errorLine)
    {
        $errorStr = sprintf(
            "%s in file %s on line %d",
            $errorString,
            $errorFile,
            $errorLine
        );
        switch ($errorNo) {
            case E_ERROR:
                static::shutHalt($errorStr, SwoolefyException::EXCEPTION_ERR);
                break;
            case E_WARNING:
                static::shutHalt($errorStr, SwoolefyException::EXCEPTION_WARNING);
                break;
            case E_NOTICE:
                static::shutHalt($errorStr, SwoolefyException::EXCEPTION_NOTICE);
                break;
            default:
                break;
        }
        return;
    }

    /**
     * 捕捉异常返回前端(重写)
     * @param App $app
     * @param \Throwable $e
     */
    public static function response(App $app, \Throwable $throwable)
    {
        $app->response->header('Content-Type', 'application/json; charset=UTF-8');

        $queryString  = isset($app->request->server['QUERY_STRING']) ? '?' . $app->request->server['QUERY_STRING'] : '';
        $exceptionMsg = $throwable->getMessage();

        if (isset($app->request->post) && !empty($app->request->post)) {
            $postRaw = json_encode($app->request->post, JSON_UNESCAPED_UNICODE);
            $errorMsg = $exceptionMsg . ' in file ' . $throwable->getFile() . ' on line ' . $throwable->getLine() . ' ||| ' . $app->request->server['REQUEST_URI'] . $queryString.' ||| '.$postRaw;
        } else {
            $errorMsg = $exceptionMsg . ' in file ' . $throwable->getFile() . ' on line ' . $throwable->getLine() . ' ||| ' . $app->request->server['REQUEST_URI'] . $queryString;
        }

        if (($code = $throwable->getCode()) == 0) {
            // common error code
            $code = -1;
        }

        if (SystemEnv::isPrdEnv() || SystemEnv::isGraEnv()) {
            $errorMsg = $exceptionMsg;
        }

        $app->beforeEnd($code, $errorMsg);

        $errorMsg .= ' ||| ' . $throwable->getTraceAsString();

        static::shutHalt($errorMsg, SwoolefyException::EXCEPTION_ERR, $throwable);

    }

    /**
     * shutHalt 记录日志(重写)
     * @param string $errorMsg
     * @param string $errorType
     */
    public static function shutHalt(
        $errorMsg,
        $errorType = SwoolefyException::EXCEPTION_ERR,
        \Throwable $throwable = null
    ) {
        $logger = LogManager::getInstance()->getLogger('error_log');
        if (!is_object($logger)) {
            _each("【Warning】Missing set 'error_log' component on " . __CLASS__ . '::' . __FUNCTION__);
            return;
        }

        $logFilePath = $logger->getLogFilePath();
        if (is_file($logFilePath)) {
            $logFileSize = filesize($logFilePath);
        } else {
            @file_put_contents($logFilePath, '');
        }

        // 定时清除这个log文件
        if (isset($logFileSize) && $logFileSize > 20 * 1024 * 1024) {
            @file_put_contents($logFilePath, '');
        }

        switch ($errorType) {
            case SwoolefyException::EXCEPTION_ERR:
                $logger->addError($errorMsg);
                break;
            case SwoolefyException::EXCEPTION_WARNING:
                $logger->addWarning($errorMsg);
                break;
            case SwoolefyException::EXCEPTION_NOTICE:
                $logger->addNotice($errorMsg);
                break;
            case SwoolefyException::EXCEPTION_INFO:
                $logger->addInfo($errorMsg);
                break;
        }

        if (in_array(SWOOLEFY_ENV, [SWOOLEFY_DEV, SWOOLEFY_GRA])) {
            _each($errorMsg);
        }

        return;
    }

}