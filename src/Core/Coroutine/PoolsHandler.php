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

use Swoole\Coroutine;
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
 * - poolsNum：池容量上限
 * - objectCount：已存在对象 + 正在创建对象；它是唯一的容量账本
 *
 * ## 并发不变量
 * - 0 <= callCount
 * - 0 <= channel.length() <= poolsNum
 * - objectCount = idle + borrowed + creating <= poolsNum
 *
 * 创建回调可能发生协程 yield。因此必须先在无 yield 的连续代码中预占
 * objectCount，再执行回调；不能根据 channel.length 或 callCount 单独判断容量。
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
     * 池内资源总账本：空闲、借出与正在创建的对象都计入。
     *
     * 该计数不保存对象引用，只保存容量状态，避免常驻 Worker 出现对象引用滞留。
     *
     * @var int
     */
    protected $objectCount = 0;

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
     * 过期、池已满或 push 超时：丢弃、释放容量账本并 decreaseCallCount，再按需补建。
     * 同一 ContainerObjectDto 只能由借出它的协程归还，避免重复/陈旧归还污染池状态。
     *
     * @param object $obj
     * @return void
     */
    public function pushObj($obj)
    {
        if (!is_object($obj) || !$obj instanceof ContainerObjectDto) {
            return;
        }

        $cid = Coroutine::getCid();
        if ($obj->__coroutineId !== $cid) {
            return;
        }

        /*
         * 在创建异步归还协程前立即撤销借出标记。这里没有 yield：
         * 重复 push 会被拒绝；对象重新借出后，旧协程的 cid 也无法归还新租约。
         */
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
                // 归还对象，设置当前组件不属于任何协程
                $obj->__coroutineId = -1;
                $pushed = $this->channel->push($obj, $this->pushTimeout);
                if ($pushed) {
                    // 归还成功：借出计数 -1，供其他协程 pop 到
                    $this->decreaseCallCount();
                } else {
                    // push 超时：对象离开池，先释放容量账本，再按需补槽
                    $this->discardObject($obj);
                    $this->decreaseCallCount();
                    $this->refillOneIfNeeded();
                }
            } else {
                // 过期或池已满：丢弃 + 释放容量账本 + 扣计数 + 按需补建
                $this->discardObject($obj);
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
            // 出 channel 对象绑定到当前协程；pushObj 用它拒绝重复、错误和陈旧归还。
            $obj->__coroutineId = Coroutine::getCid();
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

        // 1) 空池 warm-up：make() 内部先预占容量，多个协程同时进入也不会超建。
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

        // 3) 未满懒创建：容量判断由 make() 的 objectCount reservation 统一负责。
        if ($this->objectCount < $this->poolsNum) {
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
            if ($this->channel->isEmpty()) {
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
        if ($this->channel !== null && $this->objectCount < $this->poolsNum) {
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
            /*
             * 预占必须发生在 callable 之前。callable / channel->push 都可能 yield；
             * 其他协程只能看到已预占的 objectCount，不能基于旧状态重复创建。
             */
            if (!$this->reserveCreateSlot()) {
                return;
            }

            try {
                $obj = call_user_func($this->callable, $this->poolName);
                if (!is_object($obj)) {
                    throw new SystemException("Pools of {$this->poolName} build instance must return object");
                }
                $containerObject = $this->buildContainerObject($obj, $this->poolName);
                $pushed = $this->channel->push($containerObject, $this->pushTimeout);
                if (!$pushed) {
                    unset($containerObject);
                    $this->releaseCreateSlot();
                }
                unset($obj);
            } catch (\Throwable $exception) {
                $this->releaseCreateSlot();
                throw $exception;
            }
        }
    }

    /**
     * 无 yield 的「检查 + 预占」连续区间。
     *
     * @return bool 是否成功预占一个创建槽位
     */
    protected function reserveCreateSlot(): bool
    {
        if ($this->objectCount >= $this->poolsNum) {
            return false;
        }

        ++$this->objectCount;
        return true;
    }

    /**
     * 释放一个已预占或已丢弃对象的容量槽位。
     */
    protected function releaseCreateSlot(): void
    {
        if ($this->objectCount > 0) {
            --$this->objectCount;
        }
    }

    /**
     * 丢弃一个已计入 objectCount 的对象，不保留任何对象引用。
     *
     * @param object $obj
     */
    protected function discardObject(object &$obj): void
    {
        unset($obj);
        $this->releaseCreateSlot();
    }

    /**
     * @param object $object
     * @param string $poolName
     * @return ContainerObjectDto
     */
    private function buildContainerObject(object $object, string $poolName)
    {
        $containerObjectDto                  = new ContainerObjectDto();
        // 默认创建的对象，默认无协程绑定，直接存在channel中，出channel时在绑定协程ID
        $containerObjectDto->__coroutineId   = -1;
        $containerObjectDto->__objInitTime   = time();
        $containerObjectDto->__object        = $object;
        $containerObjectDto->__comAliasName  = $poolName;
        $containerObjectDto->__objExpireTime = time() + ($this->lifeTime) + (new \Random\Randomizer())->getInt(1, 10);
        return $containerObjectDto;
    }

    /**
     * 从 channel 弹出对象；可指定等待秒数（null 则用 popTimeout）。
     *
     * 弹出后若已过期：先释放旧对象容量，再经 make() 统一重建，避免把失效连接交给业务。
     *
     * @param float|null $timeout
     * @return object|null
     */
    protected function pop(?float $timeout = null)
    {
        $timeout ??= (float) $this->popTimeout;
        $containerObject = $this->channel->pop($timeout);
        if (is_object($containerObject) && !is_null($containerObject->__objExpireTime) && time() > $containerObject->__objExpireTime) {
            // 过期对象已经离开 channel，必须先从统一容量账本扣除。
            $this->discardObject($containerObject);
            $this->make(1);
            $containerObject = $this->channel->pop($timeout);
        }

        return is_object($containerObject) ? $containerObject : null;
    }

    public function clearPool()
    {
        if ($this->channel === null) {
            return;
        }

        while ($this->channel->length() > 0) {
            $obj = $this->channel->pop(0);
            if (!is_object($obj)) {
                break;
            }
            // clearPool 只清理空闲对象；借出对象仍由其后续归还/丢弃路径结算。
            $this->discardObject($obj);
        }
    }
}