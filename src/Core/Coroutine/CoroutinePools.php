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

use Swoolefy\Core\SingletonTrait;
use Swoolefy\Exception\SystemException;

/**
 * Worker 进程内的组件池注册表（单例）。每个别名一个 {@see PoolsHandler}。
 *
 * fallback 配额必须挂在这里的 Handler 上，而不是 App / EventController：
 * 请求级容器会随 end() 销毁，无法跨请求限制「同时在线的降级连接」。
 */
class CoroutinePools
{

    use SingletonTrait;

    /**
     * @var array<string, PoolsHandler>
     */
    private $pools = [];

    /**
     * 池默认配置。
     *
     * fallback.max_concurrent = null 表示「未配置」，resolve 时按 2 * max_pool_num
     * （总上限约 3x）。不能写成 0：0 表示禁止降级。PHP `isset(null)` 为 false，
     * 与「键不存在」走同一条默认分支。
     *
     * @var array<string, mixed>
     */
    const DefaultConfig = [
        'max_pool_num' => 30,
        'max_push_timeout'   => 2,
        'max_pop_timeout'    => 1,
        'max_life_timeout'   => 10,
        'fallback' => [
            'enabled' => true,
            'max_concurrent' => null,
        ],
    ];

    /**
     * 注册一个组件池。array_merge 只覆盖顶层键，fallback 子数组由
     * {@see resolveFallbackPolicy()} 再做一次嵌套合并，避免用户只写
     * max_concurrent 时把 enabled 冲掉。
     *
     * @param string $poolName
     * @param array $poolConfig
     * @param callable $constructor
     */
    public function addPool(
        string   $poolName,
        array    $poolConfig,
        callable $constructor
    )
    {
        $poolConfig             = array_merge(self::DefaultConfig, $poolConfig);
        $poolName               = trim($poolName);
        $this->pools[$poolName] = call_user_func(function () use ($poolName, $poolConfig, $constructor) {
            $poolsHandler = new PoolsHandler();
            if (isset($poolConfig['max_pool_num']) && is_numeric($poolConfig['max_pool_num'])) {
                $poolsHandler->setPoolsNum($poolConfig['max_pool_num']);
            }

            if (isset($poolConfig['max_push_timeout'])) {
                $poolsHandler->setPushTimeout($poolConfig['max_push_timeout']);
            }

            if (isset($poolConfig['max_pop_timeout'])) {
                $poolsHandler->setPopTimeout($poolConfig['max_pop_timeout']);
            }

            if (isset($poolConfig['max_life_timeout'])) {
                $poolsHandler->setLifeTime($poolConfig['max_life_timeout']);
            }

            $poolsHandler->setFallbackPolicy(...self::resolveFallbackPolicy(
                $poolConfig,
                $poolsHandler->getPoolsNum(),
                $poolName,
            ));

            $poolsHandler->setBuildCallable($constructor);
            $poolsHandler->registerPools($poolName);
            return $poolsHandler;
        });
    }

    /**
     * 解析池外降级策略。
     *
     * 规则：
     * - enabled=false → 禁止降级（max=0），池耗尽立即 503
     * - 未设置 max_concurrent（含显式 null）→ 2 * poolsNum，总上限约 3x
     * - 显式 0 → 允许配置「开着降级开关但额度为 0」，效果等同立即拒绝
     * - 负数非法，启动期直接抛错，避免带着错误配额跑生产
     *
     * max_concurrent 只约束当前 Worker 的 inflight，不是跨进程全局信号量。
     *
     * @return array{0: bool, 1: int} [enabled, maxConcurrent]
     */
    private static function resolveFallbackPolicy(array $poolConfig, int $poolsNum, string $poolName): array
    {
        // 必须嵌套 merge：顶层 array_merge 会整体替换 fallback，丢失未写的 enabled
        $fallback = array_merge(
            ['enabled' => true, 'max_concurrent' => null],
            is_array($poolConfig['fallback'] ?? null) ? $poolConfig['fallback'] : [],
        );
        $enabled = filter_var($fallback['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);
        if (!$enabled) {
            return [false, 0];
        }

        if (!isset($fallback['max_concurrent'])) {
            return [true, 2 * max(0, $poolsNum)];
        }

        if (!is_numeric($fallback['max_concurrent'])) {
            throw new SystemException("component_pools [{$poolName}] fallback.max_concurrent must be an integer >= 0");
        }

        $maxConcurrent = (int) $fallback['max_concurrent'];
        if ($maxConcurrent < 0) {
            throw new SystemException("component_pools [{$poolName}] fallback.max_concurrent must be an integer >= 0");
        }

        return [true, $maxConcurrent];
    }

    /**
     * 按别名取 Handler。未 register 时返回 null。
     *
     * Cron/Daemon 不会 addPool，调用方应改走 creatObject，不要当成致命配置错误。
     *
     * @param string $poolName
     * @return PoolsHandler|null
     */
    public function getPool(string $poolName)
    {
        if (!$poolName) {
            return null;
        }
        $poolName = trim($poolName);
        return $this->pools[$poolName] ?? null;
    }

    /**
     * getObj
     *
     * @param string $poolName
     * @return mixed
     */
    public function getObj(string $poolName)
    {
        return $this->getPool($poolName)->fetchObj();
    }

    /**
     * 使用完put对象入channel
     *
     * @param string $poolName
     * @param object $obj
     * @return void
     */
    public function putObj(string $poolName, object $obj)
    {
        $this->getPool($poolName)->pushObj($obj);
    }
}