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

namespace Swoolefy\Core;

use Closure;
use Swoolefy\Core\Coroutine\GoWaitGroup;
use Swoolefy\Exception\SystemException;

class SyncPipe
{
    protected $pipeFunctions = [];

    protected $isStart = false;

    public function start(Closure $function)
    {
        $this->pipeFunctions[] = $function;
        $this->isStart = true;
        return $this;
    }

    public function then(Closure $function)
    {
        if (!$this->isStart) {
            throw new SystemException('please exec start() first');
        }
        $this->pipeFunctions[] = $function;
        return $this;
    }

    public function run(bool $enableCoroutine = false, float $maxTimeout = 30.0)
    {
        $param = null;
        if ($enableCoroutine) {
            foreach ($this->pipeFunctions as $function) {
                $result = $this->execute($function, $maxTimeout, $param);
                $param = $result[0] ?? null;
                if ($param instanceof \Throwable) {
                    throw $param;
                }
            }
        } else {
            foreach ($this->pipeFunctions as $function) {
                $result = call_user_func($function, $param);
                $param = $result ?? null;
            }
        }
        $this->isStart = false;
        $this->pipeFunctions = [];
        return $param;
    }

    protected function execute(Closure $function, float $maxTimeOut = 3.0, $param = null)
    {
        $goWait = new GoWaitGroup();
        $count  = count([$function]);
        $goWait->add($count);
        foreach ([$function] as $key => $callBack) {
            goApp(function () use ($key, $callBack, $param, $goWait) {
                try {
                    $goWait->initResult($key, null);
                    $result = call_user_func($callBack, $param);
                    $goWait->done($key, $result ?? null, 3.0);
                } catch (\Throwable $throwable) {
                    $goWait->add(-1);
                    $goWait->done($key, $throwable ?? null, 3.0);
                }
            });
        }
        $result = $goWait->wait($maxTimeOut);
        return $result;
    }
}