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
 * - objectCount：已创建或已预留创建的对象数（空闲 + 借出 + 归还中 + 创建中）
 * - poolsNum：池容量上限（空闲 + 借出 不宜长期超过该值）
 *
 * ## 并发不变量
 * - 0 <= callCount
 * - 0 <= channel.length() <= poolsNum
 * - 0 <= objectCount <= poolsNum
 * - 空闲 + 借出 + 归还中 + 创建中 <= poolsNum
 *
 * objectCount 的预留在调用构造器前完成。构造器可能发生协程让出，
 * 因而不能仅以 callCount 或 channel.length() 判断是否还能创建对象。
 * 借用 owner/marker/active 状态保存在 ContainerObjectDto 句柄自身；PoolsHandler
 * 仅以 WeakMap 弱键跟踪仍存活的借用句柄，不反向延长句柄或底层资源生命周期。
 * 归还时先在调用 pushObj 的协程内原子认领并撤销旧句柄，再异步发布一个新容器
 * 句柄，杜绝重复归还、跨协程归还以及旧引用与下一次借用发生 ABA 混淆。
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
 * 高并发下已实现功能：
 * 创建连接前同步预占容量，保证 objectCount <= poolsNum。
 * 借出时记录对象标识和所属协程。
 * 异步归还前同步声明归还权，消除归还竞争窗口。
 * 错误协程归还、重复归还均安全忽略。
 * 旧连接句柄立即失效，防止 ABA 和过期句柄再次归还。
 * 构造失败、超时、过期、Channel 关闭及清池路径均只回收一次容量。
 *
 * 因此，多个协程同时 yield 时：
 * 不会再超额创建连接；
 * 不会因重复归还破坏计数；
 * 不会允许其他协程归还不属于自己的连接。
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
     * 已创建或已预留创建的资源数量。
     *
     * 该值覆盖空闲、借出和正在执行用户构造器的资源。check + ++ 必须处于
     * 不会发生协程让出的连续代码段，避免多个协程基于同一旧状态重复创建。
     *
     * @var int
     */
    protected $objectCount = 0;

    /**
     * 当前仍存活的借用句柄弱集合。
     *
     * WeakMap 的键不会阻止 ContainerObjectDto 和底层资源被 GC；值中也不得保存
     * 键对象。owner/active 等权威租约状态位于句柄自身，本集合只用于发现业务
     * 丢弃但未归还的句柄，并在下一次 fetch 时修正容量账本。
     *
     * @var \WeakMap<ContainerObjectDto, true>
     */
    protected \WeakMap $borrowedHandles;

    /**
     * 本池私有租约标识。
     *
     * 使用对象身份而非 spl_object_id：对象 ID 在销毁后允许复用，而 marker 的
     * 强引用贯穿 PoolsHandler 生命周期，不存在旧租约误命中新租约的 ABA 风险。
     *
     * @var object
     */
    protected object $leaseMarker;

    /**
     * @var int
     */
    protected $lifeTime = 10;

    /**
     * @var \Closure
     */
    protected $callable = null;

    public function __construct()
    {
        $this->borrowedHandles = new \WeakMap();
        $this->leaseMarker = new \stdClass();
    }

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
     * 所有权校验和“借出 -> 归还中”状态迁移在创建异步协程之前同步完成，中间没有
     * 任何协程让出点。租约状态由句柄自身保存，非本池对象、非持有协程、重复归还
     * 均直接忽略，不会修改 callCount/objectCount，也不会把仍由其他协程持有的
     * 连接发布到 Channel。
     *
     * 合法归还会把底层资源转移到新的 ContainerObjectDto，并立即清空调用方旧容器。
     * 这样即使旧引用稍后再次 push，也无法命中下一轮借用，避免对象 ID/同协程 ABA。
     * 过期、池已满、Channel 关闭或 push 超时则丢弃并按需补建空闲对象。
     *
     * @param object $obj
     * @return void
     */
    public function pushObj($obj)
    {
        $returningObject = $this->claimObjectForReturn($obj);
        if (!$returningObject instanceof ContainerObjectDto) {
            return;
        }

        $release = function () use ($returningObject): void {
            $published = false;
            try {
                $isPush = true;
                // 超过生命周期的对象不再回池，防止陈旧连接长期复用
                if (!is_null($returningObject->__objExpireTime) && time() > $returningObject->__objExpireTime) {
                    $isPush = false;
                }

                $length = $this->channel->length();
                if ($length >= $this->poolsNum) {
                    $isPush = false;
                }

                $targetObj = $returningObject->getObject();
                if ($targetObj instanceof PDOConnection) {
                    // 恢复默认false
                    $targetObj->dynamicDebug = false;
                }

                if ($isPush) {
                    $published = (bool) $this->channel->push($returningObject, $this->pushTimeout);
                }
            } catch (\Throwable $throwable) {
                // Channel 关闭、push 异常或连接清理异常统一视为未发布，进入同一丢弃路径。
                $published = false;
            }

            if (!$published) {
                // 未发布资源已脱离池，只能释放一次容量槽，再通过 reservation 补池。
                unset($returningObject);
                $this->releaseObjectSlot();
                $this->refillOneIfNeeded();
            }
        };

        try {
            $coroutineId = \Swoole\Coroutine::create($release);
            if ($coroutineId === false) {
                // 调度失败时对象已不再归业务持有，按丢弃路径回收容量，不能留下幽灵槽位。
                unset($returningObject);
                $this->releaseObjectSlot();
                $this->refillOneIfNeeded();
            }
        } catch (\Throwable $throwable) {
            // 创建归还协程失败不应破坏调用方既有 void API；容量必须在本协程内收敛。
            unset($returningObject);
            $this->releaseObjectSlot();
            $this->refillOneIfNeeded();
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
            // WeakMap 会自动删除已 GC 的借用句柄；新一轮取用前收敛其容量账本。
            $this->reconcileCollectedBorrowers();
            $obj = $this->getObj();
            if (is_object($obj)) {
                // 注册所有权与计数递增之间没有 yield；对象交给业务前即拥有唯一持有者。
                $this->registerBorrowedObject($obj);
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

        // 1) 空池 warm-up：objectCount 的 reservation 会阻止并发冷启动重复填池。
        if ($this->objectCount === 0 && $this->channel->isEmpty() && $this->poolsNum > 0) {
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

        // 3) 未满懒创建：make() 内部原子预留容量，外层不再依据过期的快照判断。
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
            if ($this->objectCount < $this->poolsNum && $this->channel->isEmpty()) {
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
     * 为刚从 Channel 取出的对象建立唯一借用租约。
     *
     * 本方法只执行内存读写，不包含任何可让出操作，因此句柄状态、弱键登记和
     * callCount++ 对其他协程表现为一个连续状态迁移。若同一容器已有活动租约，
     * 说明内部不变量已损坏，禁止把同一连接再次交给业务。
     *
     * @param ContainerObjectDto|mixed $obj
     * @throws SystemException
     */
    protected function registerBorrowedObject($obj): void
    {
        if (
            !$obj instanceof ContainerObjectDto
            || !$obj->beginPoolLease($this->leaseMarker, \Swoole\Coroutine::getCid())
        ) {
            throw new SystemException("Pools of {$this->poolName} object is already borrowed");
        }

        // WeakMap 只弱持有句柄；业务丢弃最后一个引用后，该条目可由 GC 自动移除。
        $this->borrowedHandles[$obj] = true;
        ++$this->callCount;
    }

    /**
     * 校验并原子认领一次归还，返回用于异步发布的新容器句柄。
     *
     * 关键顺序固定为：验证 marker/owner/active -> 移除弱键 -> callCount-- ->
     * 转移底层对象并使旧句柄失效。整个区间没有 Channel/IO/协程创建等让出点，
     * 所以多个协程同时归还时最多一个调用能成功；错误协程和重复调用均为只读失败。
     *
     * 新建容器用于隔离前后两次 lease：即使原持有协程保留旧 PHP 引用，下一次借用
     * 也对应不同容器身份，旧引用无法归还或代理访问已转移的底层连接。
     *
     * @param mixed $obj
     * @return ContainerObjectDto|null
     */
    protected function claimObjectForReturn($obj): ?ContainerObjectDto
    {
        if (!$obj instanceof ContainerObjectDto) {
            return null;
        }

        if (!$obj->claimPoolLease($this->leaseMarker, \Swoole\Coroutine::getCid())) {
            return null;
        }

        $targetObject = $obj->getObject();
        if (isset($this->borrowedHandles[$obj])) {
            unset($this->borrowedHandles[$obj]);
        }
        $this->decreaseCallCount();

        $returningObject = new ContainerObjectDto();
        $returningObject->__coroutineId = -1;
        $returningObject->__objInitTime = $obj->__objInitTime;
        $returningObject->__objExpireTime = $obj->__objExpireTime;
        $returningObject->__object = $targetObject;
        $returningObject->__comAliasName = $obj->__comAliasName;

        // 所有权已转移，立即撤销旧代理，防止调用方在 pushObj 返回后继续使用连接。
        $obj->__coroutineId = -1;
        $obj->__object = null;

        return $returningObject;
    }

    /**
     * 回收“句柄已被业务丢弃且已由 GC 销毁”的容量账本。
     *
     * WeakMap 只保留仍存活的活动租约；正常归还会同步删除弱键并递减 callCount，
     * 因此 callCount 与弱键数之间的正差只可能来自未调用 pushObj 的已回收句柄。
     * 对应底层资源已随容器析构，不再占用池容量，故 objectCount 也必须逐个释放。
     *
     * 整段只有计数和 WeakMap 读写，没有协程让出点。若句柄仍被业务强引用，则弱键
     * 仍存在，不会被误回收；下一次 fetch 会按统一 reservation 路径补足资源。
     */
    protected function reconcileCollectedBorrowers(): void
    {
        $liveBorrowers = count($this->borrowedHandles);
        if ($liveBorrowers >= $this->callCount) {
            return;
        }

        $collectedBorrowers = $this->callCount - $liveBorrowers;
        $this->callCount = $liveBorrowers;
        for ($i = 0; $i < $collectedBorrowers; ++$i) {
            $this->releaseObjectSlot();
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
     * 所有资源创建均必须经过本方法。每轮先预留 objectCount，再执行可能 yield
     * 的用户构造器；构造失败或 Channel 发布失败时在同一轮回滚预留。
     *
     * @param int $num
     * @throws SystemException
     */
    protected function make(int $num = 1)
    {
        if (is_null($this->callable)) {
            throw new SystemException("Callable property missing Closure");
        }

        for ($i = 0; $i < $num; $i++) {
            if (!$this->reserveCreateSlot()) {
                return;
            }

            try {
                // 此处允许构造器 yield；容量已预留，其他协程不能超额创建。
                $obj = call_user_func($this->callable, $this->poolName);
                if (!is_object($obj)) {
                    throw new SystemException("Pools of {$this->poolName} build instance must return object");
                }

                $containerObject = $this->buildContainerObject($obj, $this->poolName);
                $pushed = $this->channel->push($containerObject, $this->pushTimeout);
                if (!$pushed) {
                    // 发布失败意味着这个新对象没有进入池，必须撤销此前的预留。
                    unset($containerObject, $obj);
                    $this->releaseObjectSlot();
                } else {
                    unset($obj);
                }
            } catch (\Throwable $throwable) {
                // 构造器、封装或发布异常均不能遗留虚假的创建中容量。
                $this->releaseObjectSlot();
                throw $throwable;
            }
        }
    }

    /**
     * 原子预留一个创建槽位。
     *
     * Swoole 协程只会在可让出的操作处切换；本方法中只有比较与自增，
     * 故 check + reservation 是一个不可分割的容量判定单元。
     */
    protected function reserveCreateSlot(): bool
    {
        if ($this->objectCount >= $this->poolsNum || $this->channel->length() >= $this->poolsNum) {
            return false;
        }

        ++$this->objectCount;
        return true;
    }

    /**
     * 释放一个已丢弃或创建失败资源对应的容量槽位。
     *
     * 防御性下限保护与 callCount 的处理一致，避免异常清理路径造成负计数。
     */
    protected function releaseObjectSlot(): void
    {
        if ($this->objectCount > 0) {
            --$this->objectCount;
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
     * 弹出后若已过期：先释放该对象的容量账本，再通过 make(1) 重建并取出，
     * 避免失效对象占用 objectCount 导致池永久无法补足。
     *
     * @param float|null $timeout
     * @return object|null
     */
    protected function pop(?float $timeout = null)
    {
        $timeout ??= (float) $this->popTimeout;
        $containerObject = $this->channel->pop($timeout);
        if (is_object($containerObject) && !is_null($containerObject->__objExpireTime) && time() > $containerObject->__objExpireTime) {
            // 过期对象已离开 channel，必须先归还容量再进行新的 reservation。
            unset($containerObject);
            $this->releaseObjectSlot();
            $this->make(1);
            $containerObject = $this->channel->pop($timeout);
        }

        return is_object($containerObject) ? $containerObject : null;
    }

    /**
     * 清理当前所有空闲对象。
     *
     * 借出对象不会被本方法触碰；每个成功移除的空闲对象都同步释放一个容量槽位，
     * 从而保证定时清理后后续 fetch 可以按需重建资源。
     */
    public function clearPool()
    {
        if (($length = $this->channel->length()) > 0) {
            for ($i=0; $i<$length; $i++) {
                $obj = $this->channel->pop(0.01);
                if (is_object($obj)) {
                    unset($obj);
                    $this->releaseObjectSlot();
                }
            }
        }
    }
}