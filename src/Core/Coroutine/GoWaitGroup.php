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

use Swoole\Coroutine\Channel;
use Swoolefy\Exception\SystemException;

class GoWaitGroup
{
    /**
     * @var int
     */
    private $count = 0;

    /**
     * @var Channel
     */
    private $channel;

    /**
     * @var bool
     */
    private $waiting = false;

    /**
     * @var array
     */
    private $result = [];

    /**
     * After wait() returns, late callbacks from timed-out (or finished) batches must not touch count/result/channel.
     *
     * @var bool
     */
    private $waitCompleted = false;

    /**
     * WaitGroup constructor
     */
    public function __construct()
    {
        $this->channel = new Channel(1);
    }

    /**
     * 可以通过 use 关键字传入外部变量
     *  $country = 'China';
     *   $callBack1 = function() use($country) {
     *      sleep(3);
     *      return [
     *          'tengxun'=> 'tengxun'
     *      ];
     *      };
     *
     *   $callBack2 = function() {
     *      sleep(3);
     *      return [
     *           'baidu'=> 'baidu'
     *      ];
     *   };
     *
     *   $callBack3 = function() {
     *      sleep(1);
     *      return [
     *          'ali'=> 'ali'
     *      ];
     *   };
     *
     *   call callable
     *   $result = GoWaitGroup::batchParallelRunWait([
     *      'key1' => $callBack1,
     *      'key2' => $callBack2,
     *      'key3' => $callBack3
     *   ]);
     *
     *   var_dump($result);
     *
     * @param array<string, callable> $callBacks
     * @param float                   $maxTimeOut  总等待秒数；<=0 表示不限制
     * @param array<string, mixed>    $params
     * @param bool                    $failFast    true 时首个子协程异常立即抛到调用方
     *
     * @return array<string, mixed>
     */
    public static function batchParallelRunWait(
        array $callBacks,
        float $maxTimeOut = 3.0,
        array $params = [],
        bool $failFast = false,
    ): array {
        if ($callBacks === []) {
            return [];
        }

        $goWait = new static();
        $count = count($callBacks);
        $goWait->add($count);
        $errorChannel = $failFast ? new Channel($count) : null;

        foreach ($callBacks as $key => $callBack) {
            if ($errorChannel) {
                // failFast 仍走 goApp → EventApp，保证协程单例（db/redis 等）可用；
                // 异常在闭包内捕获写入 errorChannel，不向 goApp 外层 rethrow。
                goApp(static function () use ($key, $callBack, $params, $goWait, $errorChannel) {
                    try {
                        $goWait->initResult($key, null);
                        $param = $params[$key] ?? null;
                        $result = call_user_func($callBack, $param);
                        $goWait->done($key, $result ?? null);
                    } catch (\Throwable $throwable) {
                        if ($errorChannel !== null) {
                            $errorChannel->push($throwable, 0);
                        }
                        $goWait->done($key, null);
                    }
                });
            } else {
                goApp(static function () use ($key, $callBack, $params, $goWait, $maxTimeOut) {
                    try {
                        $goWait->initResult($key, null);
                        $param = $params[$key] ?? null;
                        $result = call_user_func($callBack, $param);
                        $goWait->done($key, $result ?? null, $maxTimeOut);
                    } catch (\Throwable $throwable) {
                        $goWait->done($key, null);
                    }
                });
            }
        }
        // 带错误通道
        if ($errorChannel instanceof Channel) {
            return $goWait->waitWithErrorChannel($maxTimeOut, $errorChannel);
        }
        return $goWait->wait($maxTimeOut);
    }

    /**
     * @param int $delta
     * @return void
     */
    public function add(int $delta = 1)
    {
        if ($this->waitCompleted) {
            throw new SystemException('WaitGroup misuse: add after wait(), create a new GoWaitGroup instance');
        }
        if ($this->waiting) {
            throw new SystemException('WaitGroup misuse: add called concurrently with wait');
        }
        $count = $this->count + $delta;
        if ($count < 0) {
            throw new SystemException('WaitGroup misuse: negative counter');
        }
        $this->count = $count;
    }


    /**
     * start
     * @return int
     */
    public function start()
    {
        if ($this->waitCompleted) {
            throw new SystemException('WaitGroup misuse: start after wait(), create a new GoWaitGroup instance');
        }
        $this->count++;
        return $this->count;
    }

    /**
     * @param string|null $key
     * @param mixed $data
     * @param float $timeout
     * @return void
     */
    public function done(
        ?string $key = null,
        $data = null,
        float $timeout = -1
    ){
        // 忽略已经完成
        if ($this->waitCompleted) {
            return;
        }
        if (!is_null($key) && $key != '') {
            $this->result[$key] = $data;
        }
        $this->count--;
        if ($this->count == 0 && $this->waiting) {
            $this->channel->push(1, $timeout);
        }
    }

    /**
     * @param string $key
     * @param mixed|null $data
     */
    public function initResult(string $key, $data = null)
    {
        if ($this->waitCompleted) {
            return;
        }
        $this->result[$key] = $data;
    }

    /**
     * @param float $maxTimeout
     * @return array
     */
    public function wait(float $maxTimeout = 3.0)
    {
        if ($this->waiting) {
            throw new SystemException('WaitGroup misuse: add called concurrently with wait');
        }
        if ($this->waitCompleted) {
            throw new SystemException('WaitGroup misuse: wait() called again on the same instance');
        }

        $deadline = $maxTimeout > 0 ? microtime(true) + $maxTimeout : null;

        while ($this->count > 0) {
            if ($deadline !== null && microtime(true) >= $deadline) {
                $this->waitCompleted = true;
                $this->reset();

                throw new SystemException(sprintf('GoWaitGroup timed out after %.2f seconds', $maxTimeout));
            }

            $this->waiting = true;
            $slice = $deadline === null ? -1 : min(0.05, max(0.001, $deadline - microtime(true)));
            $this->channel->pop($slice);
            $this->waiting = false;
        }

        $this->waitCompleted = true;
        $result = $this->result;
        $this->reset();

        return $result;
    }

    /**
     * @param float $maxTimeout  总等待秒数；<=0 表示不限制
     * 用 errorChannel 收集首个异常，wait() 结束时 rethrow（修复 failFast 竞态：任务 done() 后仍检查 error channel）
     * @return array<string, mixed>
     */
    protected function waitWithErrorChannel(float $maxTimeout = 3.0, ?Channel $errorChannel = null): array
    {
        if ($this->waiting) {
            throw new SystemException('WaitGroup misuse: add called concurrently with wait');
        }
        if ($this->waitCompleted) {
            throw new SystemException('WaitGroup misuse: wait() called again on the same instance');
        }

        $deadline = $maxTimeout > 0 ? microtime(true) + $maxTimeout : null;

        while ($this->count > 0) {
            if ($errorChannel !== null && $errorChannel->length() > 0) {
                $this->waitCompleted = true;
                $this->reset();
                $err = $errorChannel->pop(0);

                throw $err instanceof \Throwable ? $err : new SystemException('GoWaitGroup worker failed');
            }

            if ($deadline !== null && microtime(true) >= $deadline) {
                $this->waitCompleted = true;
                $this->reset();

                throw new SystemException(sprintf('GoWaitGroup timed out after %.2f seconds', $maxTimeout));
            }

            $this->waiting = true;
            $slice = $deadline === null ? -1 : min(0.05, max(0.001, $deadline - microtime(true)));
            $this->channel->pop($slice);
            $this->waiting = false;
        }

        if ($errorChannel !== null && $errorChannel->length() > 0) {
            $this->waitCompleted = true;
            $this->reset();
            $err = $errorChannel->pop(0);

            throw $err instanceof \Throwable ? $err : new SystemException('GoWaitGroup worker failed');
        }

        $this->waitCompleted = true;
        $result = $this->result;
        $this->reset();
        return $result;
    }

    /**
     * @return int
     */
    public function count(): int
    {
        return $this->count;
    }

    /**
     * reset
     * @return void
     */
    protected function reset()
    {
        $this->result = [];
        $this->count = 0;
        $this->waiting = false;
        $this->waitCompleted = false;
    }

}