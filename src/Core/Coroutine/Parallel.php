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

/**
 * Class Parallel
 * @package Workerfy\Coroutine
 * 并发限制协调用，主要针对大量的callback需要调用。因为GoWaitGroup::multiCall是没有限制协程的
 * 例如有10000个并发的话，那么GoWaitGroup::multiCall将创建10000协程并发，会导致服务提供方负载过高
 * 那么Parallel将会分批限制调用，一次调用并发N个请求，分多次完成，然后在统一返回所有结果.
 *
 * 如果不想一次性统一返回结果，而是返回一次结果出来一次，那需要改做一下:
 *
 * foreach ($chunks as $k=>$chunk)
 * {
 *      $res = GoWaitGroup::multiCall($chunk, $timeOut);
 *      // todo something
 * }
 *
 */

class Parallel {

    /**
     * @var int
     */
    private $concurrent = 5;

    /**
     * @var callable[]
     */
    private $callbacks = [];

    /**
     * @var array
     */
    private $ignoreCallbacks = [];

    /**
     * Parallel constructor.
     * @param int $concurrent
     */
    public function __construct(int $concurrent = 5) {
        $this->concurrent = $concurrent;
    }

    /**
     * @param callable $callable
     * @param null $key
     */
    public function add(callable $callable, $key = null) {
        if (null === $key) {
            $this->callbacks[] = $callable;
        } else {
            $this->callbacks[$key] = $callable;
        }
    }

    /**
     * @param float $timeOut
     * @return array
     */
    public function wait(float $timeOut = 5.0) {
        if(empty($this->callbacks)) {
            return [];
        }
        $chunks = array_chunk($this->callbacks, $this->concurrent, true);
        $result = $this->callbacks = [];
        foreach ($chunks as $k=>$chunk) {
            foreach($chunk as $key=>$callable) {
                if(in_array($key, $this->ignoreCallbacks)) unset($chunk[$key]);
            }
            $res = GoWaitGroup::multiCall($chunk, $timeOut);
            unset($chunks[$k]);
            $result = array_merge($result, $res ?? []);
        }
        return $result;
    }

    /**
     * @param $key
     */
    public function ignoreCallbacks(array $keys) {
        $this->ignoreCallbacks = $keys;
    }

    /**
     * return void
     */
    public function clear() {
        $this->callbacks = [];
    }

}