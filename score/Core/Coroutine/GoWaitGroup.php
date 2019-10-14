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

namespace Swoolefy\Core\Coroutine;

use Swoole\Coroutine\Channel;

class GoWaitGroup {
    /**
     * @var int
     */
    private $count = 0;

    /**
     * @var Channel
     */
    private $chan;

    /**
     * @var array
     */
    private $result = [];

    /**
     * WaitGroup constructor
     */
    public function __construct() {
        $this->chan = new Channel;
    }

    /**
     * add
     */
    public function go(\Closure $go_func = null) {
        $this->count++;
        if($go_func instanceof \Closure) {
            go($go_func);
        }
    }

    /**
     * start
     */
    public function start() {
        $this->count++;
        return $this->count;
    }

    /**
     * done
     */
    public function done(string $key = null, $data = null, float $timeout = -1) {
        if(!empty($key) && !empty($data)) {
            $this->result[$key] = $data;
        }
        $this->chan->push(1, $timeout);
    }

    /**
     * wait
     */
    public function wait(float $timeout = 0) {
        while($this->count--) {
            $this->chan->pop($timeout);
        }
        $result = $this->result;
        $this->reset();
        return $result;
    }

    /**
     * reset
     */
    public function reset() {
        $this->result = [];
        $this->count = 0;
    }

}