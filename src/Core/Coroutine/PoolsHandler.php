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

use Common\Library\Db\PDOConnection;
use Swoole\Coroutine\Channel;
use Swoolefy\Core\Dto\ContainerObjectDto;
use Swoolefy\Exception\SystemException;

class PoolsHandler
{
    /**
     * @var Channel
     */
    protected $channel = null;

    /**
     * @var string
     */
    protected $poolName;

    /**
     * @var int
     */
    protected $poolsNum = 30;

    /**
     * @var int
     */
    protected $pushTimeout = 2;

    /**
     * @var int
     */
    protected $popTimeout = 1;

    /**
     * @var int
     */
    protected $callCount = 0;

    /**
     * @var int
     */
    protected $lifeTime = 10;

    /**
     * @var \Closure
     */
    protected $callable = null;

    /**
     * @param int $poolsNum
     */
    public function setPoolsNum(int $poolsNum = 50)
    {
        $this->poolsNum = $poolsNum;
    }

    /**
     * @return int
     */
    public function getPoolsNum()
    {
        return $this->poolsNum;
    }

    /**
     * @param float $pushTimeout
     */
    public function setPushTimeout(float $pushTimeout = 3.0)
    {
        $this->pushTimeout = $pushTimeout;
    }

    /**
     * @return int
     */
    public function getPushTimeout()
    {
        return $this->pushTimeout;
    }

    /**
     * @param float $popTimeout
     * @return void
     */
    public function setPopTimeout(float $popTimeout = 1.0)
    {
        $this->popTimeout = $popTimeout;
    }

    /**
     * @return int
     */
    public function getPopTimeout()
    {
        return $this->popTimeout;
    }

    /**
     * @param int $lifeTime
     * @return void
     */
    public function setLifeTime(int $lifeTime)
    {
        $this->lifeTime = $lifeTime;
    }

    /**
     * @return int
     */
    public function getLifeTime()
    {
        return $this->lifeTime;
    }

    /**
     * @return string
     */
    public function getPoolName()
    {
        return $this->poolName;
    }

    /**
     * @return int
     */
    public function getCapacity()
    {
        return $this->channel->capacity;
    }

    /**
     * @return int
     */
    public function getCurrentNum()
    {
        return $this->channel->length();
    }

    /**
     * @return Channel|null
     */
    public function getChannel()
    {
        return $this->channel;
    }

    /**
     * 实例创建执行体
     * @param callable $callback
     * @return void
     */
    public function setBuildCallable(callable $callback)
    {
        $this->callable = $callback;
    }

    /**
     * @param string|null $poolName
     * @return void
     */
    public function registerPools(?string $poolName = null)
    {
        if ($poolName) {
            $this->poolName = trim($poolName);
            if (!isset($this->channel)) {
                $this->channel = new Channel($this->poolsNum);
            }
        }
    }

    /**
     * pushObj 使用完要重新push进channel
     *
     * @param object $obj
     * @return void
     */
    public function pushObj($obj)
    {
        \Swoole\Coroutine::create(function () use ($obj) {
            $isPush = true;
            if (!is_null($obj->__objExpireTime) && time() > $obj->__objExpireTime) {
                $isPush = false;
            }

            $length = $this->channel->length();
            if ($length >= $this->poolsNum) {
                $isPush = false;
            }

            $targetObj = $obj->getObject();
            if ($targetObj instanceof PDOConnection) {
                if ($targetObj->dynamicDebug === 1) {
                    $targetObj->setDebug(0);
                }
            }

            if ($isPush) {
                $this->channel->push($obj, $this->pushTimeout);
                $length = $this->channel->length();
                // 矫正
                if (($this->poolsNum - $length) == $this->callCount - 1) {
                    --$this->callCount;
                } else {
                    $this->callCount = $this->poolsNum - $length;
                }
            } else {
                unset($obj);
                --$this->callCount;
                if ($this->channel->length() < $this->poolsNum) {
                    (new \Swoolefy\Core\EventApp)->registerApp(function() {
                        $this->make(1);
                    });
                }
            }

            if ($this->callCount < 0) {
                $this->callCount = 0;
            }
        });
    }

    /**
     * @return object
     * @throws \Exception
     */
    public function fetchObj()
    {
        try {
            $obj = $this->getObj();
            is_object($obj) && $this->callCount++;
            $targetObj = $obj->getObject();
            if ($targetObj instanceof PDOConnection) {
                $targetObj->enableDynamicDebug();
            }
            return $obj;
        } catch (\Exception $exception) {
            throw $exception;
        }
    }

    /**
     * getObj
     * @return mixed
     */
    protected function getObj()
    {
        // first build object
        if ($this->callCount == 0 && $this->channel->isEmpty()) {
            if ($this->poolsNum) {
                $this->make($this->poolsNum);
            }
        } else {
            if ($this->callCount >= $this->poolsNum || $this->channel->isEmpty()) {
                usleep(10 * 1000);
            }
        }
        if ($this->channel->length() > 0) {
            return $this->pop();
        }
        return null;
    }

    /**
     * @param int $num
     * @throws SystemException
     */
    protected function make(int $num = 1)
    {
        if (is_null($this->callable)) {
            throw new SystemException("Callable property missing Closure");
        }

        for ($i = 0; $i < $num; $i++) {
            $obj = call_user_func($this->callable, $this->poolName);
            if (!is_object($obj)) {
                throw new SystemException("Pools of {$this->poolName} build instance must return object");
            }

            $containerObject = $this->buildContainerObject($obj, $this->poolName);
            $this->channel->push($containerObject, $this->pushTimeout);
            unset($obj);
        }
    }

    /**
     * @param object $object
     * @param string $poolName
     * @return ContainerObjectDto
     */
    private function buildContainerObject(object $object, string $poolName)
    {
        $containerObjectDto                  = new ContainerObjectDto();
        $containerObjectDto->__coroutineId   = \Swoole\Coroutine::getCid();
        $containerObjectDto->__objInitTime   = time();
        $containerObjectDto->__object        = $object;
        $containerObjectDto->__comAliasName  = $poolName;
        $containerObjectDto->__objExpireTime = time() + ($this->lifeTime) + rand(1, 10);
        return $containerObjectDto;
    }

    /**
     * @return object|null
     */
    protected function pop()
    {
        $containerObject = $this->channel->pop($this->popTimeout);
        if (is_object($containerObject) && !is_null($containerObject->__objExpireTime) && time() > $containerObject->__objExpireTime) {
            //rebuild object
            unset($containerObject);
            $this->make(1);
            $containerObject = $this->channel->pop($this->popTimeout);
        }

        return is_object($containerObject) ? $containerObject : null;
    }

    public function clearPool()
    {
        if ($length = $this->channel->length() > 0) {
            for ($i=0; $i<$length; $i++) {
                $obj = $this->channel->pop(0.01);
                unset($obj);
            }
        }
    }
}