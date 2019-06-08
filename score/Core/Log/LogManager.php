<?php
/**
+----------------------------------------------------------------------
| swoolefy framework bases on swoole extension development, we can use it easily!
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
+----------------------------------------------------------------------
 */

namespace Swoolefy\Core\Log;

class LogManager {

    use \Swoolefy\Core\SingletonTrait;

    protected $logger;

    /**
     * __construct
     * @param mixed $log
     */
    private function __construct() {}

    /**
     * registerLogger
     * @param  string|null $channel    
     * @param  string|null $logFilePath
     * @param  string|null $output     
     * @param  string|null $dateformat 
     * @return void                  
     */
    public function registerLogger(
        string $channel = null,
        string $logFilePath = null,
        string $output = null,
        string $dateformat = null
    ) {
        if($channel && $logFilePath) {
            $this->logger = new \Swoolefy\Tool\Log($channel, $logFilePath, $output, $dateformat);
        }
    }

    /**
     * registerLoggerByClosure
     * @param  \Closure  $func
     * @param  string $log_name
     * @return mixed
     */
    public function registerLoggerByClosure(\Closure $func, string $log_name) {
        $this->logger = call_user_func($func, $log_name);
    }

    /**
     * getLogger
     * @return mixed
     */
    public function getLogger() {
        return $this->logger;
    }

    /**
     * @param $action
     * @param $args
     */
    public function __call($action, $args) {
        $this->logger->$action(...$args);
    }

}