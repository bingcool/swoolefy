<?php
/**
+----------------------------------------------------------------------
| swoolefy framework bases on swoole extension development, we can use it easily!
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
+----------------------------------------------------------------------
*/

namespace Swoolefy\Core\Coroutine;

use Swoolefy\Core\Coroutine\CoroutinePools;

use Swoolefy\Core\BaseServer;

class CoroutinePools {

    use \Swoolefy\Core\SingletonTrait;

    private $pools = [];

    /**
     * 在workerStart可以创建一个协程池Channel
        \Swoolefy\Core\Coroutine\CoroutinePools::getInstance()->addPool('redis', function($pool_name) {
            $redis = new \App\CoroutinePools\RedisPools();
            $redis->setMaxPoolsNum();
            $redis->setMinPoolsNum();
            $redis->registerPools($pool_name);
            return $redis;
        });
     * @param  string  $name
     * @param  PoolsHandler   $handler
     * @throws mixed
     */
    public function addPool(string $pool_name, $handler) {
        $AppConf = BaseServer::getAppConf();
        if(isset($AppConf['enable_component_pools']) && is_array($AppConf['enable_component_pools']) && !empty($AppConf['enable_component_pools'])) {
            if(!in_array($pool_name, $AppConf['enable_component_pools'])) {
                throw new \Exception("pool_name={$pool_name} must in app conf['enable_component_pools'] value");
            }
        }
        if(!isset($this->pools[$pool_name])) {
            if($handler instanceof PoolsHandler) {
                $this->pools[$pool_name] = $handler;
            }else if($handler instanceof \Closure) {
                $this->pools[$pool_name] = call_user_func_array($handler, [$pool_name]);
            }
        }
    }

    /**
     * getChannel
     * @param    string   $name
     * @return   mixed
     */
    public function getPool(string $pool_name) {
        if(isset($this->pools[$pool_name])) {
            return $this->pools[$pool_name];
        }else{
            return null;
        }
    }
}