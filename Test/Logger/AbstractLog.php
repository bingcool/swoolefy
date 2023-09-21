<?php

namespace Test\Logger;

use Swoolefy\Util\Log as Logger;
use Swoolefy\Core\Log\LogManager;

abstract class AbstractLog
{
    /**
     * @var string
     */
    protected static $type;

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
        $logger = LogManager::getInstance()->getLogger(static::$type);
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
        /**
         * @var Logger $logger
         */
        $logger = LogManager::getInstance()->getLogger(static::$type);
        $logger->addError($message, $is_delay_batch, $context);
    }
}