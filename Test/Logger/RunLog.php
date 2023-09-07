<?php

namespace Test\Logger;

use Swoolefy\Util\Log as Logger;
use Swoolefy\Core\Log\LogManager;

class RunLog
{
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
        $logger = LogManager::getInstance()->getLogger('log');
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
        $logger = LogManager::getInstance()->getLogger('log');
        $logger->addError($message, $is_delay_batch, $context);
    }
}