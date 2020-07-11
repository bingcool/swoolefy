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

namespace Swoolefy\Library\Cache;

class RedisConnection {

    /**
     * @var null
     */
    protected $redis;

    /**
     * @var array
     */
    protected $lastLogs = [];

    /**
     * @param $method
     * @param $arguments
     * @param $errorMsg
     */
    protected function log($method, $arguments, $errorMsg = 'ok') {
        //记录前50个操作即可，防止在循坏中大量创建
        if(count($this->lastLogs) > 50) {
            $this->lastLogs = [];
        }
        $this->lastLogs[] = json_encode(['time'=>date('Y-m-d, H:i:s'), 'method'=>$method, 'args'=>$arguments, 'msg'=>$errorMsg],JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array
     */
    public function getLastLogs() {
        return array_map(function ($item) {
            return json_decode($item, true) ?? [];
        }, $this->lastLogs);
    }

    /**
     * __destruct
     */
    public function __destruct() {
        unset($this->redis);
        $this->logs = [];
    }

}