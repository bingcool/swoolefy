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

    private function __construct(){}

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
     * @param $action
     * @param $args
     */
    public function __call($action, $args) {
        $this->logger->$action(...$args);
    }

}