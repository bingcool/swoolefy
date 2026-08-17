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
use Swoolefy\Http\LogParamSanitizer;
use Swoolefy\Http\ResponseOutput;

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
    public static function appException(\Throwable $exception)
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

        $errorStr = sprintf(
            "%s in file %s on line %d",
            $error['message'],
            $error["file"],
            $error['line']
        );

        static::shutHalt($errorStr, SwoolefyException::EXCEPTION_ERR, $exception);
    }

    /**
     * appError 注册用户程序错误
     * @param int $errorNo
     * @param string $errorMessage
     * @param string $errorFile
     * @param int $errorLine
     * @return void
     */
    public static function handleError(int $errorNo, string $errorMessage, string $errorFile, int $errorLine)
    {
        if ((\PHP_VERSION_ID >= 80400 && in_array($errorNo, [E_NOTICE, E_DEPRECATED])) ||
            (\PHP_VERSION_ID < 80400 && in_array($errorNo, [E_NOTICE, E_DEPRECATED, E_STRICT]))
        ) {
            return;
        }
        throw new \ErrorException($errorMessage, 0, $errorNo, $errorFile, $errorLine);
    }

    /**
     * 捕捉异常(重写)
     * @param App $app
     * @param \Throwable $e
     */
    public static function response(App $app, \Throwable $throwable)
    {
        $queryString  = isset($app->swooleRequest->server['QUERY_STRING']) ? '?' . $app->swooleRequest->server['QUERY_STRING'] : '';
        $exceptionMsg = $throwable->getMessage();

        if (method_exists($throwable, 'getContextData')) {
            $contextData = $throwable->getContextData();
        }

        if (isset($app->swooleRequest->post) && !empty($app->swooleRequest->post)) {
            // 调试附加 post 必须脱敏，避免 password/token 进入响应与普通日志
            // 是否启用日志脱敏，生产环境建议启用
            if (env("ENABLE_LOG_SANITIZE", false)) {
                $postRaw = json_encode(LogParamSanitizer::sanitize($app->swooleRequest->post), JSON_UNESCAPED_UNICODE);
            } else {
                $postRaw = json_encode($app->swooleRequest->post, JSON_UNESCAPED_UNICODE);
            }
            $errorMsg = $exceptionMsg . ' in file ' . $throwable->getFile() . ' on line ' . $throwable->getLine() . ' ||| ' . $app->swooleRequest->server['REQUEST_URI'] . $queryString.' ||| '.$postRaw;
        } else {
            $errorMsg = $exceptionMsg . ' in file ' . $throwable->getFile() . ' on line ' . $throwable->getLine() . ' ||| ' . $app->swooleRequest->server['REQUEST_URI'] . $queryString;
        }

        // 异常 code 可能是 int、"401" 或 PDO SQLSTATE（如 "42S22"），必须先规范为 int
        $code = static::normalizeExceptionCode($throwable->getCode());

        if (SystemEnv::isPrdEnv() || SystemEnv::isGraEnv()) {
            $errorMsg = $exceptionMsg;
        }

        $responseOutput = new ResponseOutput($app->swooleRequest, $app->swooleResponse);
        // AuthException 等：code 为合法 HTTP 状态时同步写响应状态（避免 JSON code=401 但 HTTP 仍 200）
        // 比较必须在 int 上做：PHP 8 下 "42S22" >= 400 会按字符串比较为 true，导致 withStatus 收到非数字
        if ($code >= 400 && $code < 600) {
            $responseOutput->withStatus($code);
        }
        $responseOutput->returnJson($contextData ?? [], $code, $errorMsg);

        $errorMsg .= ' ||| ' . $throwable->getTraceAsString();

        static::shutHalt($errorMsg, SwoolefyException::EXCEPTION_ERR, $throwable);

    }

    /**
     * shutHalt 记录日志(可继承覆盖重写)
     * @param string $errorMsg
     * @param string $errorType
     * @param \Throwable|null $throwable
     */
    public static function shutHalt(
        string $errorMsg,
        $errorType,
        \Throwable|null $throwable
    ) {
        $logger = LogManager::getInstance()->getLogger('error_log');
        if (!is_object($logger)) {
            fmtPrintError("【Warning】Missing set 'error_log' component on " . __CLASS__ . '::' . __FUNCTION__);
            return;
        }

        $logFilePath = $logger->getLogFilePath();
        if (!file_exists($logFilePath)) {
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
            fmtPrintError($errorMsg);
        }
    }

    /**
     * 将异常 getCode() 规范为 int，供 JSON code 与 HTTP 状态使用。
     *
     * - int 0 → -1（业务失败码）
     * - 纯数字字符串（如 "401"）转为 int
     * - PDO SQLSTATE（如 "42S22"）等非数字字符串不是 HTTP 状态，回退 -1
     *
     * @param mixed $code
     */
    public static function normalizeExceptionCode(mixed $code): int
    {
        if (is_int($code)) {
            return $code === 0 ? -1 : $code;
        }
        if (is_string($code) && preg_match('/^-?\d+$/', trim($code)) === 1) {
            $int = (int) trim($code);

            return $int === 0 ? -1 : $int;
        }

        return -1;
    }

}