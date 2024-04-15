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

namespace Swoolefy\Core\Log;

use Swoolefy\Exception\SystemException;
use Swoolefy\Util\Log;

/**
 * Class LogManager
 * @see \Swoolefy\Util\Log
 * @mixin \Swoolefy\Util\Log
 */
class LogManager
{

    use \Swoolefy\Core\SingletonTrait;

    /**
     * @var Log
     */
    protected $logger;

    public const SQL_LOG = 'sql_log';

    public const GUZZLE_CURL_LOG = 'guzzle_curl_log';

    /**
     * __construct
     * @param mixed $log
     */
    private function __construct()
    {
    }

    /**
     * registerLogger
     * @param string|null $channel
     * @param string|null $logFilePath
     * @param string|null $output
     * @param string|null $dateformat
     * @return void
     */
    public function registerLogger(
        string $type,
        string $channel = null,
        string $logFilePath = null,
        string $output = null,
        string $dateformat = null
    )
    {
        if ($channel && $logFilePath) {
            $logger = $this->logger[$type] = new \Swoolefy\Util\Log($channel, $logFilePath, $output, $dateformat);
            $logger->setType($type);
        }
    }

    /**
     * registerLoggerByClosure
     * @param \Closure $func
     * @param string $type
     * @return mixed
     */
    public function registerLoggerByClosure(\Closure $func, string $type)
    {
        $logger = $this->logger[$type] = call_user_func($func, $type);
        if (!$logger instanceof \Swoolefy\Util\Log) {
            throw new SystemException("registerLoggerByClosure only for \Swoolefy\Util\Log, not sopport other component");
        }
        $logger->setType($type);
    }

    /**
     * getLogger
     * @param string $type
     * @return Log|null
     */
    public function getLogger(string $type): ?Log
    {
        $logger = $this->logger[$type] ?? null;
        if ($logger) {
            $hour = (int)date('H');
            if (in_array($hour,[23,0,1,2,3,4,5,6,7,8])) {
                $logFilePath = $logger->getLogFilePath();
                if (!is_file($logFilePath)) {
                    @file_put_contents($logFilePath, '');
                }
            }
        }
        return $logger;
    }

}