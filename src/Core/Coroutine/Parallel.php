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
    public function __construct(int $concurrent = 5)
    {
        $this->concurrent = $concurrent;
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
     * @param float $timeOut
     * @return array
     */
    public function runWait(float $timeOut = 5.0)
    {
        if (empty($this->callbacks)) {
            return [];
        }
        $result = [];
        $start = 0;
        while ($items = array_slice($this->callbacks, $start, $this->concurrent, true)) {
            $start = $start + $this->concurrent;
            foreach ($items as $key => $callable) {
                if (in_array($key, $this->ignoreCallbacks)) {
                    unset($items[$key]);
                }
            }

            if ($items) {
                $res = GoWaitGroup::batchParallelRunWait($items, $timeOut);
            }

            $result = array_merge($result, $res ?? []);
        }
        $this->callbacks = [];
        return $result;
    }

    /**
     * 并发限制协程数量闭包处理
     *
     * @param int $concurrent 限制的并发协程数量
     * @param array $list 数组
     * @param \Closure $handleFn 回调处理
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