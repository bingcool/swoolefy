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

use Swoolefy\Library\Db\PDOConnection;
use Swoole\Coroutine\Channel;
use Swoolefy\Core\Dto\ContainerObjectDto;
use Swoolefy\Core\Log\LogManager;
use Swoolefy\Exception\SystemException;

/**
 * 协程对象池（Channel 实现）。
 *
 * ## 计数约定
 * - channel.length：空闲对象数
 * - callCount：已借出、尚未归还的对象数
 * - poolsNum：池容量上限（空闲 + 借出 不宜长期超过该值）
 *
 * ## 取对象策略（{@see getObj()}）
 * 1. 空池 warm-up：首次借出且 channel 为空时一次性 make(poolsNum)
 * 2. 有空闲：直接 pop，避免无谓等待
 * 3. 未满懒创建：callCount < poolsNum 时再 make(1)，缓解并发冷启动
 * 4. 全借出：用 channel->pop(popTimeout) 阻塞等待归还（替代「sleep 一次就返回 null」）
 * 5. 超时边界短重试：再 pop(0.05) 两次，覆盖 push/pop 临界竞态，且不再次完整阻塞 popTimeout
 *
 * ## 归还策略（{@see pushObj()}）
 * - 未过期且未满：push 成功后安全递减 callCount
 * - push 超时 / 已过期 / 池已满：丢弃对象并 decreaseCallCount，必要时 refillOne 补回空闲槽
 *
 * 取不到对象时返回 null，上层 ComponentTrait 可降级 creatObject。
 */
class PoolsHandler
{
    /**
     * 空闲对象通道，容量 = poolsNum。
     *
     * @var Channel
     */
    protected ?Channel $channel = null;

    /**
     * @var string
     */
    protected $poolName;

    /**
     * 池容量上限。
     *
     * @var int
     */
    protected $poolsNum = 30;

    /**
     * @var int
     */
    protected $pushTimeout = 2;

    /**
     * 全借出时等待归还的最长时间（秒），见 getObj 第 4 步。
     *
     * @var int
     */
    protected $popTimeout = 1;

    /**
     * 当前已借出、尚未归还的对象数（仅在 fetchObj 成功 +1，归还/丢弃时 decreaseCallCount）。
     *
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
     * 归还对象到 channel。
     *
     * 过期、池已满或 push 超时：丢弃并 decreaseCallCount，再按需补建 1 个空闲对象，
     * 避免 callCount 虚高导致长期无法懒创建、命中率下降。
     *
     * @param object $obj
     * @return void
     */
    public function pushObj($obj)
    {
        \Swoole\Coroutine::create(function () use ($obj) {
            $isPush = true;
            // 超过生命周期的对象不再回池，防止陈旧连接长期复用
            if (!is_null($obj->__objExpireTime) && time() > $obj->__objExpireTime) {
                $isPush = false;
            }

            $length = $this->channel->length();
            if ($length >= $this->poolsNum) {
                $isPush = false;
            }

            $targetObj = $obj->getObject();
            if ($targetObj instanceof PDOConnection) {
                // 恢复默认false
                $targetObj->dynamicDebug = false;
            }

            if ($isPush) {
                $pushed = $this->channel->push($obj, $this->pushTimeout);
                if ($pushed) {
                    // 归还成功：借出计数 -1，供其他协程 pop 到
                    $this->decreaseCallCount();
                } else {
                    // push 超时：对象丢弃，仍须扣减借出计数，并尝试补槽
                    unset($obj);
                    $this->decreaseCallCount();
                    $this->refillOneIfNeeded();
                }
            } else {
                // 过期或池已满：丢弃 + 扣计数 + 有空位则补建
                unset($obj);
                $this->decreaseCallCount();
                $this->refillOneIfNeeded();
            }
        });
    }

    /**
     * 从池中取出对象；成功时 callCount+1。
     *
     * @return object|null
     * @throws \Exception
     */
    public function fetchObj()
    {
        try {
            $obj = $this->getObj();
            if (is_object($obj)) {
                // 仅在真正取到对象时记为借出，避免 null 污染 callCount
                $this->callCount++;
                $targetObj = $obj->getObject();
            }
            // 动态设置是否动态调试输出sql
            if (isset($targetObj) && $targetObj instanceof PDOConnection) {
                $targetObj->enableDynamicDebug();
            }
            return $obj;
        } catch (\Throwable $exception) {
            $msg = sprintf("fetchObj from pool=[%s] failed, error:%s", $this->poolName, $exception->getMessage());
            $systemLogger = LogManager::getInstance()->getLogger('system_error_log');
            if (is_object($systemLogger)) {
                $systemLogger->addError($msg);
            }
            fmtPrintError($msg);
            throw $exception;
        }
    }

    /**
     * 高并发取对象（详见类注释「取对象策略」）。
     *
     * 旧实现：忙时 sleep(0.01) 一次，channel 仍空则直接 null，池命中率不稳。
     * 现实现：优先空闲 / 懒创建，否则阻塞等待归还，超时后再短重试。
     *
     * @return object|null
     */
    protected function getObj()
    {
        if ($this->channel === null) {
            return null;
        }

        // 1) 空池 warm-up：首次借出时一次性填满，降低并发冷启动风暴
        if ($this->callCount === 0 && $this->channel->isEmpty() && $this->poolsNum > 0) {
            $this->make($this->poolsNum);
            return $this->pop();
        }

        // 2) 有空闲：直接取，不进入等待
        if ($this->channel->length() > 0) {
            $obj = $this->pop();
            if (is_object($obj)) {
                return $obj;
            }
        }

        // 3) 未满懒创建：仍有容量预算时补 1 个，缓解「池未建满但并发已到」
        if ($this->callCount < $this->poolsNum) {
            $this->make(1);
            $obj = $this->pop();
            if (is_object($obj)) {
                return $obj;
            }
        }

        // 4) 全借出：在 channel 上阻塞等待归还，最长 popTimeout（秒）
        $obj = $this->pop();
        if (is_object($obj)) {
            return $obj;
        }

        // 5) 超时边界短重试：覆盖 push 刚完成 / pop 刚超时的竞态；用 0.05s 而非再次完整 popTimeout
        for ($i = 0; $i < 2; $i++) {
            if ($this->callCount < $this->poolsNum && $this->channel->isEmpty()) {
                $this->make(1);
            }
            $obj = $this->pop(0.05);
            if (is_object($obj)) {
                return $obj;
            }
        }

        // 仍失败则 null，由上层降级新建实例
        return null;
    }

    /**
     * 安全递减借出计数，避免并发归还时减到负数。
     */
    protected function decreaseCallCount(): void
    {
        if ($this->callCount > 0) {
            --$this->callCount;
        }
    }

    /**
     * 丢弃对象后若 channel 未满，异步补建 1 个空闲对象，维持池水位。
     */
    protected function refillOneIfNeeded(): void
    {
        if ($this->channel !== null && $this->channel->length() < $this->poolsNum) {
            \Swoolefy\Core\EventApp::run(function () {
                $this->make(1);
            });
        }
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
        $containerObjectDto->__objExpireTime = time() + ($this->lifeTime) + (new \Random\Randomizer())->getInt(1, 10);
        return $containerObjectDto;
    }

    /**
     * 从 channel 弹出对象；可指定等待秒数（null 则用 popTimeout）。
     *
     * 弹出后若已过期：丢弃并 make(1) 再 pop 一次，避免把失效连接交给业务。
     *
     * @param float|null $timeout
     * @return object|null
     */
    protected function pop(?float $timeout = null)
    {
        $timeout ??= (float) $this->popTimeout;
        $containerObject = $this->channel->pop($timeout);
        if (is_object($containerObject) && !is_null($containerObject->__objExpireTime) && time() > $containerObject->__objExpireTime) {
            // 过期对象重建后再取，保持池内可用实例数
            unset($containerObject);
            $this->make(1);
            $containerObject = $this->channel->pop($timeout);
        }

        return is_object($containerObject) ? $containerObject : null;
    }

    public function clearPool()
    {
        if (($length = $this->channel->length()) > 0) {
            for ($i=0; $i<$length; $i++) {
                $obj = $this->channel->pop(0.01);
                unset($obj);
            }
        }
    }
}