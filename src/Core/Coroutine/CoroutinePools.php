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
        'pools_num' => 30,
        'push_timeout' => 2,
        'pop_timeout' => 1,
        'live_time' => 10
    ];

    /**
     * @param string $poolName
     * @param array $config
     * @param callable $constructor
     */
    public function addPool(
        string   $poolName,
        array    $config,
        callable $constructor
    )
    {
        $config                 = array_merge(self::DefaultConfig, $config);
        $poolName               = trim($poolName);
        $this->pools[$poolName] = call_user_func(function () use ($poolName, $config, $constructor) {
            $poolsHandler = new PoolsHandler();
            if (isset($config['pools_num']) && is_numeric($config['pools_num'])) {
                $poolsHandler->setPoolsNum($config['pools_num']);
            }

            if (isset($config['push_timeout'])) {
                $poolsHandler->setPushTimeout($config['push_timeout']);
            }

            if (isset($config['pop_timeout'])) {
                $poolsHandler->setPopTimeout($config['pop_timeout']);
            }
            if (isset($config['live_time'])) {
                $poolsHandler->setLiveTime($config['live_time']);
            }

            $poolsHandler->setBuildCallable($constructor);
            $poolsHandler->registerPools($poolName);
            return $poolsHandler;
        });
    }

    /**
     * getChannel
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
     * 获取一个对象
     * @param string $poolName
     * @return mixed
     */
    public function getObj(string $poolName)
    {
        return $this->getPool($poolName)->fetchObj();
    }

    /**
     * 使用完put对象入channel
     * @param string $poolName
     * @param $obj
     * @return void
     */
    public function putObj(string $poolName, $obj)
    {
        $this->getPool($poolName)->pushObj($obj);
    }
}