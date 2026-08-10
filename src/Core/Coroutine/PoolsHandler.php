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
 * - 未过期且未满：在借用协程内同步 push，成功后安全递减 callCount
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
        return $this->channel?->capacity ?? 0;
    }

    /**
     * @return int
     */
    public function getCurrentNum()
    {
        return $this->channel?->length() ?? 0;
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
     * 过期、池已满、channel 已关闭或 push 超时：丢弃、释放容量账本并 decreaseCallCount，再按需补建。
     *
     * 归还必须在借用协程内同步完成，不能再通过 Coroutine::create() 延后。
     *
     * 容量为 1 且存在高扇出等待者时，异步释放会留下一个危险窗口：调用方已把租约
     * 标记为 -1 并结束，而真正的 channel->push() 尚未运行。此时唯一对象既不在
     * channel，也没有有效借主；所有 fetchObj() 都只能在 pop() 上等待。若调度器
     * 没有及时运行释放协程，整个池会饥饿。同步 push 将「消费租约 → 唤醒一个等待者
     * 或放回空闲 channel → 结算账本」收敛为同一条归还路径。
     *
     * ContainerObjectDto 的 __coroutineId 仅保存当前租约的所属协程：
     * - fetchObj() 成功后写入当前 cid；
     * - 本方法接受归还后立即改为 -1；
     * - -1 表示已归还/正在归还，拒绝重复、跨协程和陈旧归还。
     *
     * 每次成功归还会为 channel 创建新的 DTO 外壳，而不是把调用方持有的 DTO 重新
     * 入队。底层组件对象仍复用，但旧外壳永久保持 -1；因此调用方即使保留旧引用，
     * 也无法在对象被下一租约借出后把该旧 handle 误归还。
     *
     * 已借出的对象必然对应一个可用槽位：它离开 channel 时腾出了该槽位。因此使用
     * 非阻塞 push(0) 即可；若仍失败，说明 channel 被关闭或账本/外部 channel 状态
     * 已不一致，按丢弃并补槽的失败路径恢复，绝不把业务协程卡在归还阶段。
     *
     * @param object $obj
     * @return void
     */
    public function pushObj($obj)
    {
        if (!is_object($obj) || !$obj instanceof ContainerObjectDto) {
            return;
        }

        if (!$this->isChannelOpen() || $obj->__coroutineId !== Coroutine::getCid()) {
            unset($obj);
            return;
        }

        /*
         * 这是无 yield 的“校验归属 + 消费租约”连续区间。先消费租约再进入同步
         * release，可保证重复、跨协程和陈旧 handle 都不能参与后续账本结算。
         */
        $obj->__coroutineId = -1;

        // 不创建异步释放协程：见方法注释中的 capacity=1 高扇出饥饿窗口。
        $this->releaseBorrowedObject($obj);
    }

    /**
     * 结算一个已经被 pushObj() 同步接受的借出对象。
     *
     * 无论 channel push 是否成功，finally 都恰好结算一次 callCount；失败路径还要
     * 释放 objectCount，并通过 make() 的预占协议补槽。这样 close、timeout、过期和
     * pool 已满不会造成“借出数不减”或“容量槽永久丢失”。
     *
     * @param ContainerObjectDto $obj
     */
    protected function releaseBorrowedObject(ContainerObjectDto $obj): void
    {
        $pushed = false;
        $idleObject = null;
        try {
            $targetObj = $obj->getObject();
            if ($targetObj instanceof PDOConnection) {
                // 恢复默认 false，避免上一次借用的动态 SQL 调试泄漏到下一次借用。
                $targetObj->dynamicDebug = false;
            }
            $isExpired = !is_null($obj->__objExpireTime) && time() > $obj->__objExpireTime;
            if (!$isExpired && $this->isChannelOpen() && $this->channel->length() < $this->poolsNum) {
                // 放回 channel，不绑定任何协程。借出时已腾出一个槽位，归还不能等待。
                $obj->__coroutineId = -1;
                /**
                 * $obj可能会被引用另一个对象，不能被回收。
                 * 每次成功归还会为 channel 创建新的 DTO 外壳，而不是把调用方持有的 DTO 重新
                 * 入队。底层组件对象仍复用，但旧外壳永久保持 -1；因此调用方即使保留旧引用，
                 * 也无法在对象被下一租约借出后把该旧 handle 误归还。
                 */
                $idleObject = clone $obj; // 只复制标量元数据，__object 对象还是底层引用
                $idleObject->__coroutineId = -1;
                $pushed = $this->channel->push($idleObject, 0);
            }
        } catch (\Throwable $exception) {
            // 归还路径不能让 channel 异常逃逸并中断后续账本结算。
            $pushed = false;
        } finally {
            if (!$pushed) {
                unset($idleObject);
                // 对象未回到 channel，已不属于池，必须释放容量后才允许重建。
                $this->discardObject($obj);
            }

            // 已接受租约保证该分支对每次借出仅执行一次。
            $this->decreaseCallCount();

            if (!$pushed) {
                $this->refillOneIfNeeded();
            }
        }
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
            if (!is_object($obj)) {
                return null;
            }
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
        if (!$this->isChannelOpen()) {
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
        if ($this->isChannelOpen() && $this->objectCount < $this->poolsNum) {
            /*
             * 当前调用方已经是异步归还协程。直接调用可确保 discard -> reserve -> make
             * 处于同一条可审计的路径，且不会因 EventApp 上下文冲突丢失补槽。
             */
            $this->make(1);
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
            if (!$this->isChannelOpen()) {
                return;
            }

            /*
             * 预占必须发生在 callable 之前。callable / channel->push 都可能 yield；
             * 其他协程只能看到已预占的 objectCount，不能基于旧状态重复创建。
             */
            if (!$this->reserveCreateSlot()) {
                return;
            }

            try {
                $targetObj = call_user_func($this->callable, $this->poolName);
                if (!is_object($targetObj)) {
                    throw new SystemException("Pools of {$this->poolName} build instance must return object");
                }
                $containerObject = $this->buildContainerObject($targetObj, $this->poolName);
                $pushed = $this->channel->push($containerObject, $this->pushTimeout);
                if (!$pushed) {
                    unset($containerObject);
                    $this->releaseCreateSlot();
                }
                unset($targetObj);
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
     * channel 可能被外部调用 getChannel()->close() 关闭。关闭后的第一次 push/pop 会把
     * errCode 置为 SWOOLE_CHANNEL_CLOSED；随后不能再 pop、push、make 或 refill。
     * 归还路径会丢弃对象并释放账本，避免已关闭 channel 上反复「预占 → 构造 → push
     * 失败」而造成无意义重建。这个判断不持有对象，仅保护通道生命周期。
     */
    protected function isChannelOpen(): bool
    {
        return $this->channel instanceof Channel
            && $this->channel->errCode !== \SWOOLE_CHANNEL_CLOSED;
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
        $containerObjectDto->__tagetObjectId = spl_object_id($object);
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
        if (!$this->isChannelOpen()) {
            return null;
        }

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
        if (!$this->isChannelOpen()) {
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