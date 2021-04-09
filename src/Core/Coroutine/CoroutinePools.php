<?php
/**
+----------------------------------------------------------------------
| swoolefy framework bases on swoole extension development, we can use it easily!
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| @see https://github.com/bingcool/swoolefy
+----------------------------------------------------------------------
*/

namespace Swoolefy\Core\Coroutine;

class CoroutinePools {

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
        string $pool_name,
        array $config,
        callable $constructor
    ) {
        $config = array_merge(self::DefaultConfig, $config);
        $pool_name = trim($pool_name);
        $this->pools[$pool_name] = call_user_func(function() use($pool_name, $config, $constructor) {
            $poolsHandler = new PoolsHandler();
            if(isset($config['pools_num']) && is_numeric($config['pools_num'])) {
                $poolsHandler->setPoolsNum($config['pools_num']);
            }

            if(isset($config['push_timeout'])) {
                $poolsHandler->setPushTimeout($config['push_timeout']);
            }

            if(isset($config['pop_timeout'])) {
                $poolsHandler->setPopTimeout($config['pop_timeout']);
            }
            if(isset($config['live_time'])) {
                $poolsHandler->setLiveTime($config['live_time']);
            }

            $poolsHandler->setBuildCallable($constructor);
            $poolsHandler->registerPools($pool_name);
            return $poolsHandler;
        });
    }

    /**
     * getChannel
     * @param  string $name
     * @return PoolsHandler
     */
    public function getPool(string $pool_name) {
        if(!$pool_name) {
            return null;
        }
        $pool_name = trim($pool_name);
        return $this->pools[$pool_name] ?? null;
    }

    /**
     * 获取一个对象
     * @param string $pool_name
     * @return mixed
     */
    public function getObj(string $pool_name) {
        return $this->getPool($pool_name)->fetchObj();
    }

    /**
     * 使用完put对象入channel
     * @param string $pool_name
     * @param $obj
     * @return mixed
     */
    public function putObj(string $pool_name, $obj) {
        $this->getPool($pool_name)->pushObj($obj);
    }
}