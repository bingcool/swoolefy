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

namespace Swoolefy\Core\Coroutine;

use Swoolefy\Core\SystemEnv;

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
class Parallel
{

    /**
     * 协程最大限制并发数
     *
     * @var int
     */
    private $maxConcurrent = 50;
    /**
     * @var callable[]
     */
    private $callbacks = [];

    /**
     * @var array
     */
    private $ignoreCallbacks = [];

    /**
     * 协程最大限制并发数
     * @param int $maxConcurrent
     */
    public function __construct(int $maxConcurrent = 50)
    {
        $this->maxConcurrent = $maxConcurrent;
    }

    /**
     * @param callable $callable
     * @param string $key
     */
    public function add(callable $callable, string $key = null)
    {
        if (null === $key) {
            $this->callbacks[] = $callable;
        } else {
            $this->callbacks[$key] = $callable;
        }
    }

    /**
     * runWait 并发后等待结果返回
     *
     * @param float $maxTimeOut
     * @return array
     */
    public function runWait(float $maxTimeOut = 5.0)
    {
        if (empty($this->callbacks)) {
            return [];
        }
        $result = [];
        $start = 0;
        $concurrent = count($this->callbacks);
        if ($concurrent > $this->maxConcurrent) {
            $concurrent = $this->maxConcurrent;
        }
        while ($items = array_slice($this->callbacks, $start, $concurrent, true)) {
            $start = $start + $concurrent;
            foreach ($items as $key => $callable) {
                if (in_array($key, $this->ignoreCallbacks)) {
                    unset($items[$key]);
                }
            }

            if ($items) {
                $res = GoWaitGroup::batchParallelRunWait($items, $maxTimeOut);
            }

            $result = array_merge($result, $res ?? []);
        }
        $this->callbacks = [];
        return $result;
    }

    /**
     * 并发限制协程数量闭包处理,无需等待结果返回
     *
     * @param int $concurrent 限制的每批并发协程数量,防止瞬间产生大量的协程拖垮下游服务或者DB
     * @param array $list 数组
     * @param \Closure $handleFn 回调处理
     * @param float $sleepTime
     * @return void
     */
    public static function run(int $concurrent, array &$list, \Closure $handleFn, float $sleepTime = 0.01)
    {
        $start = 0;
        while ($items = array_slice($list, $start, $concurrent, true)) {
            $start = $start + $concurrent;
            foreach ($items as $key=>$item) {
                goApp(function () use ($key, $item, $handleFn) {
                    $handleFn($item, $key);
                });
            }

            if (SystemEnv::isWorkerService()) {
                if ($sleepTime >= 0.5) {
                    $sleepTime = 0.5;
                }
                \Swoole\Coroutine\System::sleep($sleepTime);
            }
        }

        if (isset($items)) {
            unset($items);
        }
    }

    /**
     * @param array $key
     * @return void
     */
    public function ignoreCallbacks(array $keys)
    {
        $this->ignoreCallbacks = $keys;
    }

    /**
     *@return void
     */
    public function clear()
    {
        $this->callbacks = [];
    }

}