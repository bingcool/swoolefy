<?php

namespace Test\Logger;

use Swoolefy\Exception\SystemException;
use Swoolefy\Util\Log as Logger;
use Swoolefy\Core\Log\LogManager;

abstract class AbstractLog
{
    /**
     * @var string
     */
    protected static $infoLogType;

    /**
     * @var string
     */
    protected static $errorLogType;

    /**
     * @param $message
     * @param bool $is_delay_batch
     * @param array $context
     * @return void
     */
    public static function info($message, bool $is_delay_batch = false, array $context = [])
    {
        /**
         * @var Logger $logger
         */
        $logger = LogManager::getInstance()->getLogger(static::$infoLogType);
        //var_dump($logger);
        $logger->addInfo($message, $is_delay_batch, $context);
    }

    /**
     * @param $message
     * @param bool $is_delay_batch
     * @param array $context
     * @return void
     */
    public static function error($message, bool $is_delay_batch = false, array $context = [])
    {
        if (empty(static::$errorLogType)) {
            throw new SystemException("Miss Property errorLogType");
        }

        /**
         * @var Logger $logger
         */
        $logger = LogManager::getInstance()->getLogger(static::$errorLogType);
        $logger->addError($message, $is_delay_batch, $context);
    }
}