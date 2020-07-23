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

namespace Swoolefy\Core\Coroutine;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

class GoWaitGroup {
    /**
     * @var int
     */
    private $count = 0;

    /**
     * @var Channel
     */
    private $channel;

    /**
     * @var array
     */
    private $result = [];

    /**
     * WaitGroup constructor
     */
    public function __construct() {
        $this->channel = new Channel;
    }

    /**
     * @param \Closure $callBack
     * @param mixed ...$params
     */
    public function go(\Closure $callBack, ...$params) {
        Coroutine::create(function (...$params) use($callBack) {
            try{
                $this->count++;
                $callBack->call($this, ...$params);
            }catch (\Throwable $throwable) {
                $this->count--;
                throw $throwable;
            }
        }, ...$params);
    }

    /**
     * start
     */
    public function start() {
        $this->count++;
        return $this->count;
    }

    /**
     * @param string|null $key
     * @param null $data
     * @param float $timeouts
     */
    public function done(string $key = null, $data = null, float $timeout = -1) {
        if(!empty($key) && !empty($data)) {
            $this->result[$key] = $data;
        }
        $this->channel->push(1, $timeout);
    }

    /**
     * @param float $timeout
     * @return array
     */
    public function wait(float $timeout = 0) {
        while($this->count--) {
            $this->channel->pop($timeout);
        }
        $result = $this->result;
        $this->reset();
        return $result;
    }

    /**
     * reset
     */
    protected function reset() {
        $this->result = [];
        $this->count = 0;
    }

}