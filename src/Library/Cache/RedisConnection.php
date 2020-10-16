<?php
/**
+----------------------------------------------------------------------
| swoolefy framework bases on swoole extension development, we can use it easily!
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| @see https://github.com/bingcool/swoolefy
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
     * @var int
     */
    protected $spendLogNum = 20;

    /**
     * @param $method
     * @param $arguments
     * @param $errorMsg
     */
    protected function log($method, $arguments, $errorMsg = 'ok') {
        if(count($this->lastLogs) > $this->spendLogNum) {
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
     * @param int $logNum
     */
    public function setLimitLogNum(int $spendLogNum) {
        //最大记录前50个操作即可，防止在循坏中大量创建
        if($spendLogNum > 50) {
            $spendLogNum = 50;
        }
        $this->spendLogNum = $spendLogNum;
    }

    /**
     * __destruct
     */
    public function __destruct() {
        unset($this->redis);
        $this->lastLogs = [];
    }

}