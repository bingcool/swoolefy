<?php
namespace Test\Exception;

use Swoolefy\Core\Application;
use Swoolefy\Core\Log\LogManager;
use Swoolefy\Core\SwoolefyException;

class ExceptionHandle extends \Swoolefy\Core\SwoolefyException
{
    /**
     * shutHalt 输出错误日志
     * @param string $errorMsg
     * @param string $errorType
     */
    public static function shutHalt($errorMsg, $errorType = SwoolefyException::EXCEPTION_ERR, \Throwable $throwable = null)
    {
        var_dump($throwable->getMessage());
        $logger = LogManager::getInstance()->getLogger('error_log');
        if (!is_object($logger)) {
            _each("【Warning】Missing set 'error_log' component on " . __CLASS__ . '::' . __FUNCTION__);
            return;
        }

        $logFilePath = $logger->getLogFilePath();
        if (!is_file($logFilePath)) {
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
    }
}
