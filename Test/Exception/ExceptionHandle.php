<?php
namespace Test\Exception;

use Swoolefy\Core\Application;
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
        parent::shutHalt($errorMsg, $errorType);
        var_dump($throwable->getMessage());
        //$db = Application::getApp()->get('db');
    }
}
