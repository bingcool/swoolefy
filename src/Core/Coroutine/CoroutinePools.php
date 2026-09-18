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

class CoroutinePools
{

    use SingletonTrait;

    /**
     * @var array
     */
    private $pools = [];

    /**
     * @var array
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
     * @return array{0: bool, 1: int} [enabled, maxConcurrent]
     */
    private static function resolveFallbackPolicy(array $poolConfig, int $poolsNum, string $poolName): array
    {
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
     * getChannel
     *
     * @param string $poolName
     * @return PoolsHandler
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