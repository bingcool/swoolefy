<?php
namespace Test\Exception;

use Swoolefy\Core\Log\LogManager;
use Swoolefy\Core\SwoolefyException;

class ExceptionHandle extends \Swoolefy\Core\SwoolefyException
{
    /**
     * shutHalt 输出错误日志
     * @param string $errorMsg
     * @param string $errorType
     * @param \Throwable|null $throwable
     */
    public static function shutHalt($errorMsg, $errorType, ?\Throwable $throwable)
    {
        $logger = LogManager::getInstance()->getLogger('system_error_log');

        if (!is_object($logger)) {
            fmtPrintError("Missing set 'error_log' component on " . __CLASS__ . '::' . __FUNCTION__);
            return;
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

        if (in_array(SWOOLEFY_ENV, [SWOOLEFY_DEV, SWOOLEFY_TEST])) {
            fmtPrintError($errorMsg);
        }
    }
}
