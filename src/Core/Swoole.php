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

use Swoolefy\Core\Coroutine\CoroutineManager;
use Swoolefy\Exception\SystemException;

class Swoole extends BaseObject
{

    use \Swoolefy\Core\ComponentTrait, \Swoolefy\Core\ServiceTrait;

    /**
     * $appConf
     * @var array
     */
    public $appConf = [];

    /**
     * @var int
     */
    protected $fd;

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
     * @param array $appConf
     */
    public function __construct(array $appConf = [])
    {
        $this->appConf     = array_merge($this->appConf, $appConf);
        $this->coroutineId = CoroutineManager::getInstance()->getCoroutineId();
    }

    /**
     * init
     * @param mixed $recv
     * @return void
     */
    protected function _init(mixed $recv = null)
    {
        static::init($recv);
    }

    /**
     * bootstrap
     * @param mixed $recv
     */
    protected function _bootstrap(mixed $recv = null)
    {
        static::bootstrap($recv);
        if (isset(Swfy::getConf()['application_service']) && !empty(Swfy::getConf()['application_service'])) {
            $applicationService = Swfy::getConf()['application_service'];
            if (class_exists($applicationService)) {
                $applicationService::bootstrap($recv);
            }
        }
    }

    /**
     * init 当执行run方法时首先会执行init->bootstrap
     * @param mixed $recv
     * @return void
     */
    public function init(mixed $recv)
    {
    }

    /**
     * bootstrap
     * @param mixed $recv
     * @return void
     */
    public function bootstrap(mixed $recv)
    {
    }

    /**
     * run instance
     * @param int $fd
     * @param mixed $recv
     * @return void
     * @throws \Exception
     */
    public function run(?int $fd, mixed $recv)
    {
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
    public static function getCurrentWorkerId(): int
    {
        return Swfy::getServer()->worker_id;
    }

    /**
     * isWorkerProcess
     * @return bool
     * @throws \Exception
     */
    public static function isWorkerProcess(): bool
    {
        return Swfy::isWorkerProcess();
    }

    /**
     * isTaskProcess
     * @return bool
     * @throws \Exception
     */
    public static function isTaskProcess(): bool
    {
        return Swfy::isTaskProcess();
    }

    /**
     * @param $mixedParams
     */
    public function setMixedParams(mixed $mixedParams)
    {
        $this->mixedParams = $mixedParams;
    }

    /**
     * @param array $rpcPackHeader
     */
    public function setRpcPackHeader(array $rpcPackHeader)
    {
        $this->rpcPackHeader = $rpcPackHeader;
    }

    /**
     * @return mixed
     */
    public function getMixedParams()
    {
        return $this->mixedParams;
    }

    /**
     * getRpcPackHeader
     * @return array
     */
    public function getRpcPackHeader()
    {
        if (!$this->isWorkerProcess()) {
            throw new SystemException(sprintf("%s::getRpcPackHeader() only can use in worker process", __CLASS__));
        }
        if (!BaseServer::isRpcApp()) {
            throw new SystemException(sprintf("%s::getRpcPackHeader() method only can be called by TCP or RPC server!, because only rpc have pack setting", __CLASS__));
        }

        return $this->rpcPackHeader;
    }

    /**
     * getRpcPackBodyParams
     * @return mixed
     */
    public function getRpcPackBodyParams()
    {
        if (!$this->isWorkerProcess()) {
            throw new SystemException(sprintf("%s::getRpcPackBodyParams() only can use in worker process", __CLASS__));
        }
        if (!BaseServer::isRpcApp()) {
            throw new SystemException(sprintf("%s::getRpcPackBodyParams() method only can be called by TCP or RPC server!, because only rpc have pack setting", __CLASS__));
        }

        return $this->mixedParams;
    }

    /**
     * getUdpData
     * @return mixed
     */
    public function getUdpData()
    {
        if (!$this->isWorkerProcess()) {
            throw new SystemException(sprintf("%s::getUdpData() only can use in worker process", __CLASS__));
        }

        if (!BaseServer::isUdpApp()) {
            throw new SystemException(sprintf("%s::getUdpData() method only can be called by UDP server", __CLASS__));
        }

        return $this->mixedParams;
    }

    /**
     * getWebsocketMsg
     * @return mixed
     */
    public function getWebsocketMsg()
    {
        if (!$this->isWorkerProcess()) {
            throw new SystemException(sprintf("%s::getWebsocketMsg() only can use in worker process", __CLASS__));
        }

        if (!BaseServer::isWebsocketApp()) {
            throw new SystemException(sprintf("%s::getWebsocketMsg() method only can be called by WEBSOCKET server", __CLASS__));
        }

        return $this->mixedParams;
    }

    /**
     * getFd worker进程中可以读取到值，task进程不能，默认返回null
     * @return int
     */
    public function getFd()
    {
        return $this->fd;
    }

    /**
     * @return string|SwoolefyException
     */
    public function getExceptionClass()
    {
        return BaseServer::getExceptionClass();
    }

    /**
     * afterRequest 请求结束后注册钩子执行操作
     * @param callable $callback
     * @param bool $prepend
     * @return bool
     */
    public function afterRequest(callable $callback, bool $prepend = false)
    {
        return Hook::addHook(Hook::HOOK_AFTER_REQUEST, $callback, $prepend);
    }

    /**
     * defer
     * @return void
     */
    protected function defer()
    {
        if (\Swoole\Coroutine::getCid() >= 0) {
            $this->isDefer = true;
            \Swoole\Coroutine\defer(function () {
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