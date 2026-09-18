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

use Swoolefy\Core\Coroutine\CoroutinePools;
use Swoolefy\Core\Coroutine\PoolFallbackLease;
use Swoolefy\Core\Coroutine\PoolsHandler;
use Swoolefy\Core\Dto\ContainerObjectDto;
use Swoolefy\Core\Runtime\RuntimeRegistry;
use Swoolefy\Exception\ComponentPoolExhaustedException;
use Swoolefy\Exception\SystemException;

/**
 * 组件容器（App / EventController / Swoole 共用）。
 *
 * 连接池路径的技术约束：
 * - 池对象进 componentPoolsObjIds，请求结束 push 回 channel
 * - 池耗尽后的降级对象不进池，额度挂在 Worker 级 PoolsHandler 的 lease 上
 * - 配额用尽立即 503，不再次等待
 * - 本进程未 addPool（Cron/Daemon）时，即使 conf 写了 component_pools 也走 creatObject
 */
trait ComponentTrait
{

    /**
     * 当前请求/协程作用域内已解析的组件实例。
     *
     * @var array<string, mixed>
     */
    protected $containers = [];

    /**
     * 已启用连接池的组件别名（来自 app_conf.component_pools 的 keys）。
     *
     * @var list<string>
     */
    protected $componentPools = [];

    /**
     * 本请求从连接池借出的对象 spl_object_id。
     *
     * 只记录 fetchObj() 成功的对象。fallback creatObject 的结果不得写入：
     * pushComponentPools() 只归还这里登记过的 id，避免把一次性连接推进 channel 污染池。
     *
     * @var list<int>
     */
    protected $componentPoolsObjIds = [];

    /**
     * @return ContainerObjectDto|void
     */
    public function initCoreComponent()
    {
        $coreComponents = $this->coreComponents();
        if (!empty($coreComponents)) {
            $components = Swfy::getAppConf()['components'];
            foreach ($coreComponents as $comAliasName) {
                $func = $components[$comAliasName] ?? null;
                if ($func instanceof \Closure && !isset($this->containers[$comAliasName])) {
                    $object = call_user_func($func, $comAliasName);
                    $this->containers[$comAliasName] = $this->buildContainerObject($object, $comAliasName);
                }
            }
        }
    }

    /**
     * creatObject
     *
     * @param string $comAliasName
     * @param \Closure $definition
     * @return mixed
     * @throws SystemException
     */
    public function creatObject(string $comAliasName, \Closure $definition)
    {
        // dynamic create component object
        if (!isset($this->containers[$comAliasName]) || !is_object($this->containers[$comAliasName])) {
            $object = call_user_func($definition, $comAliasName);
            return $this->containers[$comAliasName] = $this->buildContainerObject($object, $comAliasName);
        } else {
            return $this->containers[$comAliasName];
        }
    }

    /**
     * makeNewObject
     *
     * @param string $comAliasName
     * @return mixed
     * @throws SystemException
     */
    public function makeNewObject(string $comAliasName)
    {
        // dynamic create component object
        $components = Swfy::getAppConf()['components'];
        $definition = $components[$comAliasName] ?? null;
        if (!is_callable($definition)) {
            throw new SystemException("Component '{$comAliasName}' definition is not callable");
        }
        $object = call_user_func($definition, $comAliasName);
        return $this->buildContainerObject($object, $comAliasName);
    }

    /**
     * getDependencies
     * @param string $class
     * @return array
     * @throws \ReflectionException
     */
    protected function getDependencies(string $class)
    {
        $dependencies = [];
        $reflection = new \ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        if (!is_null($constructor)) {
            foreach ($constructor->getParameters() as $param) {
                if ($param->isDefaultValueAvailable()) {
                    $dependencies[] = $param->getDefaultValue();
                } else {
                    $dependencies[] = null;
                }
            }
        }

        return [$reflection, $dependencies];
    }

    /**
     * @param object $object
     * @return ContainerObjectDto
     */
    private function buildContainerObject(object $object, string $comAliasName)
    {
        $containerObjectDto = new ContainerObjectDto();
        $containerObjectDto->__coroutineId = \Swoole\Coroutine::getCid();
        $containerObjectDto->__objInitTime = time();
        $containerObjectDto->__object = $object;
        $containerObjectDto->__comAliasName = $comAliasName;
        $containerObjectDto->__tagetObjectId = spl_object_id($object);

        $appConf = BaseServer::getAppConf();
        if (!empty($appConf['component_pools']) && is_array($appConf['component_pools'])) {
            $liveTime = $appConf['component_pools'][$comAliasName]['max_life_timeout'] ?? 10;
            $randomizer = new \Random\Randomizer();
            $containerObjectDto->__objExpireTime = time() + $liveTime + $randomizer->getInt(1, 10);
        }else {
            $containerObjectDto->__objExpireTime = null;
        }

        return $containerObjectDto;
    }

    /**
     * getComponents
     * @param string $comAliasName
     * @return mixed
     */
    public function getComponents(?string $comAliasName = null)
    {
        if ($comAliasName && isset($this->containers[$comAliasName])) {
            return $this->containers[$comAliasName];
        }
        return $this->containers;
    }

    /**
     * 释放组件引用。池化对象由 {@see pushComponentPools()} 先归还 channel；
     * 这里负责释放 fallback 租约，避免请求结束后面额度泄漏。
     *
     * @param string|array|null $comAliasName
     * @param bool $isAll
     * @return bool
     */
    public function clearComponent($comAliasName = null, bool $isAll = false)
    {
        if ($isAll) {
            foreach ($this->containers as $obj) {
                $this->releaseFallbackLease($obj);
            }
            $this->containers = [];
            return true;
        }

        if (is_string($comAliasName)) {
            $comAliasName = (array)$comAliasName;
        } else if (is_array($comAliasName)) {
            $comAliasName = array_unique($comAliasName);
        } else {
            return false;
        }

        foreach ($comAliasName as $aliasName) {
            if (isset($this->containers[$aliasName])) {
                $this->releaseFallbackLease($this->containers[$aliasName]);
                unset($this->containers[$aliasName]);
            }
        }

        return true;
    }

    /**
     * coreComponents 定义核心组件
     * @return array
     */
    protected function coreComponents()
    {
        return [];
    }

    /**
     * getOpenPoolsOfComponent
     * @return array
     */
    public function getEnablePoolsOfComponent()
    {
        return $this->componentPools ?? [];
    }

    /**
     * 解析组件。连接池别名在协程内走 {@see getPooledOrFallback()}：
     * 池命中借出；耗尽则按 Worker 级 fallback 配额建连或立即 503。
     *
     * 同一 cid 再次 get() 必须命中 containers，否则会再占一条连接/额度。
     * 该判断依赖 DTO 的 {@see ContainerObjectDto::__isset()}。
     *
     * @param string $name
     * @return ContainerObjectDto|bool
     */
    final public function get(string $name)
    {
        $appConf    = BaseServer::getAppConf();
        $components = $appConf['components'];
        $cid = \Swoole\Coroutine::getCid();
        if (isset($this->containers[$name])) {
            if (is_object($this->containers[$name])) {
                $containerObject = $this->containers[$name];
                // 同一协程单例：必须命中，否则会再借池或再占 fallback 额度。
                // isset() 依赖 ContainerObjectDto::__isset()，不能删。
                if (isset($containerObject->__coroutineId) && $containerObject->__coroutineId == $cid) {
                    return $containerObject;
                } else {
                    $app = Application::getApp($cid);
                    if (is_object($app)) {
                        $containerObject = $app->getComponents($name);
                        if (is_object($containerObject)) {
                            unset($app);
                            return $containerObject;
                        } else {
                            return $app->creatObject($name, $components[$name]);
                        }
                    }
                }
            }

            $this->clearComponent($name);
            return $this->creatObject($name, $components[$name]);
        }

        if (empty($this->componentPools)) {
            if (!empty($appConf['component_pools']) && is_array($appConf['component_pools']) ) {
                $enableComponentPools = array_keys($appConf['component_pools']);
                $this->componentPools = $enableComponentPools;
            }
        }

        if (array_key_exists($name, $components)) {
            if (in_array($name, $this->componentPools, true) && $cid >= 0) {
                $poolHandler = CoroutinePools::getInstance()->getPool($name);
                // 只有本进程真正 addPool 之后才走池/配额。Cron/Daemon 等 Worker Service
                // 不会 registerComponentPools，app.conf 里的 component_pools 只给 HTTP Worker 用；
                // 此时必须 creatObject，不能当成「池丢了」抛错，否则拉配置/心跳全部失败。
                if ($poolHandler instanceof PoolsHandler) {
                    return $this->getPooledOrFallback($name, $components[$name], $poolHandler);
                }
            }
            return $this->creatObject($name, $components[$name]);
        }

        return false;

    }

    /**
     * 池命中则借出；fetchObj 超时 null 后走 fallback 配额，用尽立即 503。
     *
     * 调用方已确认 $poolHandler 存在。本进程未 register 的别名不得进入这里。
     *
     * 关键路径（禁止改成「再等一轮 popTimeout」）：
     * 1. fetchObj 成功 → 记入 componentPoolsObjIds，请求结束 push 回池。
     * 2. fetchObj null → 立即 reserveFallback（无 yield）。失败抛 503。
     * 3. reserve 成功再 creatObject；建连失败必须 releaseFallback，否则额度空洞。
     * 4. 降级 DTO 挂 {@see PoolFallbackLease}，不进 componentPoolsObjIds。
     *
     * @param \Closure $definition
     */
    private function getPooledOrFallback(string $name, \Closure $definition, PoolsHandler $poolHandler): ContainerObjectDto
    {
        try {
            $this->containers[$name] = $poolHandler->fetchObj();
        } catch (\Throwable $throwable) {
            RuntimeRegistry::metrics()?->poolFetchError($name);
            throw $throwable;
        }

        if (isset($this->containers[$name]) && is_object($this->containers[$name])) {
            RuntimeRegistry::metrics()?->poolFetched($name);
            $objId = spl_object_id($this->containers[$name]);
            if (!in_array($objId, $this->componentPoolsObjIds, true)) {
                $this->componentPoolsObjIds[] = $objId;
            }

            return $this->containers[$name];
        }

        RuntimeRegistry::metrics()?->poolFetchError($name);

        // 池内等待已经结束；这里只做账本判断，禁止再 fetch / sleep
        if (!$poolHandler->reserveFallback()) {
            try {
                RuntimeRegistry::metrics()?->poolFallbackRejected($name);
            } catch (\Throwable) {
            }
            throw new ComponentPoolExhaustedException(
                $name,
                $poolHandler->getPoolsNum(),
                $poolHandler->getFallbackMax(),
                $poolHandler->getFallbackInflight(),
            );
        }

        try {
            $dto = $this->creatObject($name, $definition);
            // 租约必须在 creatObject 成功之后挂上：成功前失败由下面 catch 回滚
            $dto->__fallbackLease = new PoolFallbackLease($poolHandler);
            try {
                RuntimeRegistry::metrics()?->poolFallbackCreated($name);
            } catch (\Throwable) {
            }

            return $dto;
        } catch (\Throwable $throwable) {
            $poolHandler->releaseFallback();
            throw $throwable;
        }
    }

    /**
     * 幂等归还 fallback 额度。池化对象的 __fallbackLease 为 null，调用是空操作。
     */
    private function releaseFallbackLease(mixed $obj): void
    {
        if (!$obj instanceof ContainerObjectDto) {
            return;
        }
        $lease = $obj->__fallbackLease;
        if ($lease instanceof PoolFallbackLease) {
            $lease->release();
        }
    }

    /**
     * @param string $name
     * @return bool
     */
    public function has(string $name)
    {
        if(isset($this->containers[$name]) && is_object($this->containers[$name])) {
            return true;
        }
        return false;
    }

    /**
     * 请求结束把本请求借出的池化对象还回 channel。
     *
     * 只处理 componentPoolsObjIds 中的 spl_object_id；fallback 对象不在该名单里，
     * 随后由 clearComponent 关连接并释放租约，禁止 pushObj。
     *
     * @return bool
     */
    protected function pushComponentPools()
    {
        if (empty($this->componentPools) || empty($this->componentPoolsObjIds)) {
            return false;
        }

        foreach ($this->componentPools as $name) {
            if (isset($this->containers[$name])) {
                $obj = $this->containers[$name];
                if (is_object($obj)) {
                    $objId = spl_object_id($obj);
                    $key = array_search($objId, $this->componentPoolsObjIds);
                    if ($key !== false) {
                        try {
                            CoroutinePools::getInstance()->getPool($name)->pushObj($obj);
                            // 仅在已确认的池化对象归还后计数。
                            try {
                                RuntimeRegistry::metrics()?->poolReleased($name);
                            } catch (\Throwable $throwable) {
                                // 忽略异常
                            }
                            unset($this->containers[$name], $this->componentPoolsObjIds[$key]);
                        } catch (\Throwable $throwable) {
                            BaseServer::catchException($throwable);
                        } finally {
                            if (isset($this->containers[$name])) {
                                unset($this->containers[$name]);
                            }
                            if (isset($this->componentPoolsObjIds[$key])) {
                                unset($this->componentPoolsObjIds[$key]);
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * __get
     * @param string $name
     * @return mixed
     */
    final public function __get(string $name)
    {
        return $this->get($name);
    }

    /**
     * 按组件名释放。池化对象 push 回 channel；不在池 id 名单里的视为 fallback，只还额度。
     *
     * @param string $name
     */
    public function __unset(string $name)
    {
        // 直接操作 containers，禁止魔术方法内再次访问同名魔术属性造成递归
        if (!isset($this->containers[$name])) {
            return;
        }
        // 池化组件先归还再删除，与 end() 释放协议一致
        if (!empty($this->componentPools) && in_array($name, $this->componentPools, true)) {
            $obj = $this->containers[$name];
            if (is_object($obj)) {
                $objId = spl_object_id($obj);
                $key = array_search($objId, $this->componentPoolsObjIds, true);
                if ($key !== false) {
                    CoroutinePools::getInstance()->getPool($name)->pushObj($obj);
                    RuntimeRegistry::metrics()?->poolReleased($name);
                    unset($this->componentPoolsObjIds[$key]);
                } else {
                    $this->releaseFallbackLease($obj);
                }
            }
        }
        unset($this->containers[$name]);
    }

    /**
     * __isset
     * @param string $name
     * @return bool
     */
    public function __isset(string $name): bool
    {
        // 直接检查容器项，避免 isset($this->$name) 再次触发魔术方法
        return isset($this->containers[$name]);
    }

    /**
     * __set
     * @param string $name
     * @param mixed $value
     * @return mixed
     */
    public function __set(string $name, $value)
    {
        if (isset($this->containers[$name])) {
            return $this->containers[$name];
        } else {
            if ($value instanceof \Closure || is_array($value)) {
                return $this->creatObject($name, $value);
            }
            return false;
        }
    }
} 