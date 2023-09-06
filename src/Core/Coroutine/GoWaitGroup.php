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
use Swoolefy\Core\BaseServer;
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
     * @param \Closure $callBack
     * @param mixed ...$params
     */
    public function goApp(\Closure $callBack, ...$params)
    {
        goApp(function () use ($callBack, $params) {
            try {
                $this->count++;
                call_user_func($callBack, ...$params);
            } catch (\Throwable $throwable) {
                $this->count--;
                BaseServer::catchException($throwable);
            }
        });
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
     *   $result = GoWaitGroup::multiCall([
     *      'key1' => $callBack1,
     *      'key2' => $callBack2,
     *      'key3' => $callBack3
     *   ]);
     *
     *   var_dump($result);
     *
     * @param array $callBacks
     * @param float $timeOut
     * @return array
     */
    public static function multiCall(array $callBacks, float $timeOut = 3.0)
    {
        $goWait = new static();
        foreach ($callBacks as $key => $callBack) {
            $goWait->count++;
            goApp(function () use ($key, $callBack, $goWait) {
                try {
                    $goWait->initResult($key);
                    $result = call_user_func($callBack);
                    $goWait->done($key, $result, 3.0);
                } catch (\Throwable $throwable) {
                    $goWait->count--;
                    BaseServer::catchException($throwable);
                }
            });
        }
        $result = $goWait->wait($timeOut);
        return $result;
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
        if (!empty($key) && !empty($data)) {
            $this->result[$key] = $data;
        }
        $count = $this->count -1;
        $this->count = $count;
        if ($this->count == 0 && $this->waiting) {
            $this->channel->push(1, $timeout);
        }
    }

    /**
     * @param string $key
     * @param null $data
     */
    public function initResult(string $key, $data = null)
    {
        $this->result[$key] = $data;
    }

    /**
     * @param float $timeout
     * @return array
     */
    public function wait(float $timeout = 0)
    {
        if ($this->waiting) {
            throw new SystemException('WaitGroup misuse: add called concurrently with wait');
        }

        if ($this->count > 0) {
            $this->waiting = true;
            $this->channel->pop($timeout);
        }
        $result = $this->result;
        $this->reset();
        return $result;
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