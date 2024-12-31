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
     * @param array $callBacks
     * @param float $maxTimeOut
     * @param array $params
     * @return array
     */
    public static function batchParallelRunWait(array $callBacks, float $maxTimeOut = 3.0, array $params = []): array
    {
        $goWait = new static();
        $count  = count($callBacks);
        $goWait->add($count);
        foreach ($callBacks as $key => $callBack) {
            goApp(function () use ($key, $callBack, $params, $goWait) {
                try {
                    $goWait->initResult($key, null);
                    $param = $params[$key] ?? null;
                    $result = call_user_func($callBack, $param);
                    $goWait->done($key, $result ?? null, 3.0);
                } catch (\Throwable $throwable) {
                    $goWait->add(-1);
                    throw $throwable;
                }
            });
        }
        $result = $goWait->wait($maxTimeOut);
        return $result;
    }

    /**
     * @param int $delta
     * @return void
     */
    public function add(int $delta = 1)
    {
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
        string $key = null,
        $data = null,
        float $timeout = -1
    )
    {
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

        if ($this->count > 0) {
            $this->waiting = true;
            $this->channel->pop($maxTimeout);
            $this->waiting = false;
        }
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
    }

}