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

use Swoolefy\Core\BaseServer;

class CoroutinePools {

    use \Swoolefy\Core\SingletonTrait;

    private $pools = [];

    /**
     * 在workerStart可以创建一个协程池Channel
     * 'enable_component_pools' => [
        'redis' => [
            'min_pools_num'=>10,
            'max_pools_num'=>30,
            'push_timeout'=>1.5,
            'pop_timeout'=>1,
            'live_time'=>10 * 60
            ]
        ],
     *
     * @param  string  $pool_name 与配置的component_name对应
     * @param  mixed   $handler
     * @throws mixed
     */
    public function addPool() {
        $app_conf = BaseServer::getAppConf();
        if(isset($app_conf['enable_component_pools']) && is_array($app_conf['enable_component_pools']) && !empty($app_conf['enable_component_pools'])) {
            foreach($app_conf['enable_component_pools'] as $pool_name =>$component_pool) {
                $args = [$pool_name];
                if(!isset($this->pools[$pool_name])) {
                    $this->pools[$pool_name] = call_user_func_array(function($pool_name) use($component_pool) {
                        $poolsHandler = new PoolsHandler();
                        if(isset($component_pool['pools_num']) && is_numeric($component_pool['pools_num'])) {
                            $poolsHandler->setPoolsNum($component_pool['pools_num']);
                        }

                        if(isset($component_pool['push_timeout'])) {
                            $poolsHandler->setPushTimeout($component_pool['push_timeout']);
                        }

                        if(isset($component_pool['pop_timeout'])) {
                            $poolsHandler->setPopTimeout($component_pool['pop_timeout']);
                        }
                        if(isset($component_pool['live_time'])) {
                            $poolsHandler->setLiveTime($component_pool['live_time']);
                        }
                        $poolsHandler->registerPools($pool_name);
                        return $poolsHandler;
                    }, $args);
                }
            }
        }
    }

    /**
     * getChannel
     * @param    string   $name
     * @return   mixed
     */
    public function getPool(string $pool_name) {
        if(!$pool_name) {
            return null;
        }
        $pool_name = trim($pool_name);
        if(isset($this->pools[$pool_name])) {
            return $this->pools[$pool_name];
        }else{
            return null;
        }
    }
}