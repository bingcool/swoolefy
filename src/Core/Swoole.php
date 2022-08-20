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
use Swoolefy\Core\Coroutine\CoroutineManager;

class Swoole extends BaseObject
{

    use \Swoolefy\Core\ComponentTrait, \Swoolefy\Core\ServiceTrait;

    /**
     * 应用层配置
     * @var array
     */
    public $app_conf = null;

    /**
     * fd连接句柄标志
     * @var int
     */
    public $fd = null;

    /**
     * rpc、udp、websocket传递的参数寄存属性
     * @var mixed
     */
    private $mixedParams;

    /**
     * rpc的包头数据
     * @var array
     */
    protected $rpcPackHeader = [];

    /**
     * @var bool
     */
    protected $isDefer = false;

    /**
     * __construct
     * @param array $config
     */
    public function __construct(array $config = [])
    {
        $this->app_conf = $config;
    }

    /**
     * init
     * @return void
     */
    protected function _init($recv = null)
    {
        static::init($recv);
    }

    /**
     * bootstrap
     */
    protected function _bootstrap($recv = null)
    {
        static::bootstrap($recv);
        if (isset(Swfy::$conf['application_service']) && !empty(Swfy::$conf['application_service'])) {
            $application_service = Swfy::$conf['application_service'];
            if (class_exists($application_service)) {
                Swfy::$conf['application_service']::bootstrap($recv);
            }
        }
    }

    /**
     * init 当执行run方法时首先会执行init->bootstrap
     * @param mixed $recv
     * @return void
     */
    protected function init($recv)
    {
    }

    /**
     * bootstrap
     * @param mixed $recv
     * @return void
     */
    protected function bootstrap($recv)
    {
    }

    /**
     * run instance
     * @return void
     * @throws \Exception
     */
    public function run($fd, $recv)
    {
        $this->coroutine_id = CoroutineManager::getInstance()->getCoroutineId();
        $this->fd = $fd;
        $this->creatObject();
        Application::setApp($this);
        $this->defer();
        $this->_init($recv);
        $this->_bootstrap($recv);
    }

    /**
     * getCurrentWorkerId
     * @return int
     */
    public static function getCurrentWorkerId()
    {
        return Swfy::getServer()->worker_id;
    }

    /**
     * isWorkerProcess
     * @return bool
     * @throws \Exception
     */
    public static function isWorkerProcess()
    {
        return Swfy::isWorkerProcess();
    }

    /**
     * isTaskProcess
     * @return bool
     * @throws \Exception
     */
    public static function isTaskProcess()
    {
        return Swfy::isTaskProcess();
    }

    /**
     * @param int $coroutine_id
     * @return int
     */
    public function setCid($coroutine_id = null)
    {
        if (empty($coroutine_id)) {
            $coroutine_id = CoroutineManager::getInstance()->getCoroutineId();
        }
        $this->coroutine_id = $coroutine_id;
        return $this->coroutine_id;
    }

    /**
     * @param $mixed_params
     */
    public function setMixedParams($mixed_params)
    {
        $this->mixedParams = $mixed_params;
    }

    /**
     * @param array $rpc_pack_header
     */
    public function setRpcPackHeader(array $rpc_pack_header)
    {
        $this->rpcPackHeader = $rpc_pack_header;
    }

    /**
     * @return mixed
     */
    public function getMixedParams()
    {
        return $this->mixedParams;
    }

    /**
     * getCid
     * @return int
     */
    public function getCid()
    {
        return $this->coroutine_id;
    }

    /**
     * getRpcPackHeader
     * @return array
     * @throws Exception
     */
    public function getRpcPackHeader()
    {
        if (!$this->isWorkerProcess()) {
            throw new \Exception(sprintf("%s::getRpcPackHeader() only can use in worker process", __CLASS__));
        }
        if (!BaseServer::isRpcApp()) {
            throw new \Exception(sprintf("%s::getRpcPackHeader() method only can be called by TCP or RPC server!, because only rpc have pack setting", __CLASS__));
        }
        return $this->rpcPackHeader;

    }

    /**
     * getRpcPackBodyParams
     * @return mixed
     * @throws Exception
     */
    public function getRpcPackBodyParams()
    {
        if (!$this->isWorkerProcess()) {
            throw new \Exception(sprintf("%s::getRpcPackBodyParams() only can use in worker process", __CLASS__));
        }
        if (!BaseServer::isRpcApp()) {
            throw new \Exception(sprintf("%s::getRpcPackBodyParams() method only can be called by TCP or RPC server!, because only rpc have pack setting", __CLASS__));
        }

        return $this->mixedParams;
    }

    /**
     * getUdpData
     * @return mixed
     * @throws Exception
     */
    public function getUdpData()
    {
        if (!$this->isWorkerProcess()) {
            throw new \Exception(sprintf("%s::getUdpData() only can use in worker process", __CLASS__));
        }

        if (!BaseServer::isUdpApp()) {
            throw new \Exception(sprintf("%s::getUdpData() method only can be called by UDP server", __CLASS__));
        }

        return $this->mixedParams;
    }

    /**
     * getWebsocketMsg
     * @return mixed
     * @throws Exception
     */
    public function getWebsocketMsg()
    {
        if (!$this->isWorkerProcess()) {
            throw new \Exception(sprintf("%s::getWebsocketMsg() only can use in worker process", __CLASS__));
        }

        if (!BaseServer::isWebsocketApp()) {
            throw new \Exception(sprintf("%s::getWebsocketMsg() method only can be called by WEBSOCKET server", __CLASS__));
        }

        return $this->mixedParams;
    }

    /**
     * getFd worker进程中可以读取到值，task进程不能，默认返回null
     * @return mixed
     */
    public function getFd()
    {
        return $this->fd;
    }

    /**
     * @return string | SwoolefyException
     */
    public function getExceptionClass()
    {
        return BaseServer::getExceptionClass();
    }

    /**
     * afterRequest 请求结束后注册钩子执行操作
     * @param mixed $callback
     * @param bool $prepend
     * @return bool
     */
    public function afterRequest(callable $callback, bool $prepend = false)
    {
        return Hook::addHook(Hook::HOOK_AFTER_REQUEST, $callback, $prepend);
    }

    /**
     *pushComponentPools
     * @return bool
     */
    public function pushComponentPools()
    {
        if (empty($this->componentPools) || empty($this->componentPoolsObjIds)) {
            return false;
        }
        foreach ($this->componentPools as $name) {
            if (isset($this->container[$name])) {
                $obj = $this->container[$name];
                if (is_object($obj)) {
                    $objId = spl_object_id($obj);
                    if (in_array($objId, $this->componentPoolsObjIds)) {
                        CoroutinePools::getInstance()->getPool($name)->pushObj($obj);
                    }
                }
            }
        }
    }

    /**
     * defer
     * @return void
     */
    protected function defer()
    {
        if (\Swoole\Coroutine::getCid() > 0) {
            $this->isDefer = true;
            defer(function () {
                $this->end();
            });
        }
    }

    /**
     * @return void
     */
    public function end()
    {
        // call hook callable
        Hook::callHook(Hook::HOOK_AFTER_REQUEST);
        // log handle
        $this->handleLog();
        // remove
        ZFactory::removeInstance();
        // push obj pools
        $this->pushComponentPools();
        // remove App Instance
        Application::removeApp();
    }

}