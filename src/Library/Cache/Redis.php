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

/**
 * @see \Redis
 * @mixin \Redis
 */
class Redis extends RedisConnection {

    /**
     * @var \Redis
     */
    protected $redis;

    /**
     * @var array
     */
    protected $config = [];

    /**
     * @var string
     */
    protected $password;

    /**
     * @var bool
     */
    protected $isPersistent = false;

    /**
     * @var array
     */
    protected $lastLogs = [];

    /**
     * Redis constructor
     */
    public function __construct() {
        $this->buildRedis();
    }

    /**
     * buildRedis
     */
    protected function buildRedis() {
        $this->redis = new \Redis();
    }

    /**
     * @param string $host
     * @param int $port
     * @param float $timeout
     * @param null $reserved
     * @param int $retry_interval
     * @param float $read_timeout
     * @return $this
     */
    public function connect(
        string $host,
        int $port = 6379,
        float $timeout = 2.0,
        $reserved = null,
        int $retry_interval = 0,
        float $read_timeout = 0.0
    ) {
        $this->redis->connect($host, $port, $timeout, $reserved, $retry_interval, $read_timeout);
        $this->config = [$host, $port, $timeout, $reserved, $retry_interval, $read_timeout];
        $this->isPersistent = false;
        $this->log(__FUNCTION__,$this->config);
        return $this;
    }

    /**
     * @param string $host
     * @param int $port
     * @param float $timeout
     * @param null $persistent_id
     * @param int $retry_interval
     * @param float $read_timeout
     * @return $this
     */
    public function pconnect(
        string $host,
        int $port = 6379,
        float $timeout = 0.0,
        $persistent_id = null,
        int $retry_interval = 0,
        float $read_timeout = 0.0
    ) {
        $this->redis->pconnect($host, $port, $timeout, $persistent_id, $retry_interval, $read_timeout);
        $this->config = [$host, $port, $timeout, $persistent_id, $retry_interval, $read_timeout];
        $this->isPersistent = true;
        $this->log(__FUNCTION__, $this->config);
        return $this;
    }

    /**
     * reConnect
     */
    protected function reConnect() {
        $config = $this->config;
        if($this->isPersistent) {
            $this->pconnect(...$config);
        }else {
            $this->connect(...$config);
        }
        $this->auth($this->password);
    }

    /**
     * @param mixed $password
     */
    public function auth($password) {
        $this->password = $password;
        $this->redis->auth($password);
    }

    /**
     * @method \Redis $name
     * @param $name
     * @param $arguments
     */
    public function __call($method, $arguments) {
        try {
            $this->log($method, $arguments,"start to exec method={$method}");
            $result = $this->redis->{$method}(...$arguments);
            $this->log($method, $arguments);
            return $result;
        }catch(\Exception $e) {
            $this->log($method, $arguments, $e->getMessage());
            $this->log($method, $arguments, 'start to reConnect');
            $this->reConnect();
            $this->log($method, $arguments, "reConnect successful, start to try exec method={$method} again");
            $result = $this->redis->{$method}(...$arguments);
            $this->log($method, $arguments,'retry ok');
            return $result;
        }catch(\Throwable $t) {
            $this->log($method, $arguments, 'retry failed,errorMsg='.$t->getMessage());
            throw $t;
        }
    }

    /**
     * @return array
     */
    public function getConfig() {
        return $this->config;
    }

    /**
     * @return bool
     */
    public function isConnect() {
        if($this->redis->ping() == '+PONG') {
            return true;
        }
        return false;
    }
}