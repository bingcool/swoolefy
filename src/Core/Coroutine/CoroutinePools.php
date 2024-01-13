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

class CoroutinePools
{

    use \Swoolefy\Core\SingletonTrait;

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
        'max_life_timeout'   => 10
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

            $poolsHandler->setBuildCallable($constructor);
            $poolsHandler->registerPools($poolName);
            return $poolsHandler;
        });
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