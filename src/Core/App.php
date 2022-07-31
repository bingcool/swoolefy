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

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Core\Coroutine\CoroutinePools;
use Swoolefy\Core\Coroutine\CoroutineManager;

class App extends \Swoolefy\Core\Component
{

    use \Swoolefy\Core\AppTrait, \Swoolefy\Core\ServiceTrait;

    /**
     * $request
     * @var Request
     */
    public $request = null;

    /**
     * $response
     * @var Response
     */
    public $response = null;

    /**
     * $app_conf 当前应用层的配置
     * @var array
     */
    public $app_conf = null;

    /**
     * $coroutine_id
     * @var int
     */
    public $coroutine_id;

    /**
     * $controllerInstance
     * @var BController
     */
    protected $controllerInstance = null;

    /**
     * @var bool
     */
    protected $isEnd = false;

    /**
     * $isDefer
     * @var bool
     */
    protected $isDefer = false;

    /**
     * __construct
     * @param array $config
     */
    public function __construct(array $conf = [])
    {
        $this->app_conf = $conf;
    }

    /**
     * init
     * @return void
     */
    protected function _init()
    {
        if (isset($this->app_conf['session_start']) && $this->app_conf['session_start']) {
            if (is_object($this->get('session'))) {
                $this->get('session')->start();
            };
        }
    }

    /**
     * before request application handle
     */
    protected function _bootstrap()
    {
        $conf = BaseServer::getConf();
        if (isset($conf['application_index'])) {
            $application_index = $conf['application_index'];
            if (class_exists($application_index)) {
                $conf['application_index']::bootstrap($this->getRequestParams());
            }
        }
    }

    /**
     * run
     * @param Request $request
     * @param Response $response
     * @param mixed $extendData
     * @return mixed
     * @throws \Throwable
     */
    public function run(Request $request, Response $response, $extendData = null)
    {
        try {
            $this->parseHeaders($request);
            $this->coroutine_id = CoroutineManager::getInstance()->getCoroutineId();
            parent::creatObject();
            $this->request  = $request;
            $this->response = $response;
            Application::setApp($this);
            $this->defer();
            $this->_init();
            $this->_bootstrap();
            if (!$this->catchAll()) {
                $route = new HttpRoute($extendData);
                $route->dispatch();
            }
        } catch (\Throwable $throwable) {
            $exceptionHandle = $this->getExceptionClass();
            $exceptionHandle::response($this, $throwable);
        } finally {
            if (!$this->isDefer) {
                $this->onAfterRequest();
                $this->end();
            }
        }
    }

    /**
     * @param Request $request
     */
    protected function parseHeaders(Request $request)
    {
        foreach ($request->server as $key => $value) {
            $upper = strtoupper($key);
            $request->server[$upper] = $value;
            unset($request->server[$key]);
        }

        foreach ($request->header as $key => $value) {
            $_key = 'HTTP_' . strtoupper(str_replace('-', '_', $key));
            $request->server[$_key] = $value;
            $request->header[$_key] = $value;
        }
    }

    /**
     * setAppConf
     * @param array $conf
     */
    public function setAppConf(array $conf = [])
    {
        static $isResetAppConf;
        if (!isset($isResetAppConf)) {
            if (!empty($conf)) {
                $this->app_conf = $conf;
                Swfy::setAppConf($conf);
                BaseServer::setAppConf($conf);
                $isResetAppConf = true;
            }
        }
    }

    /**
     * @param BController $controller
     */
    public function setControllerInstance(BController $controller)
    {
        $this->controllerInstance = $controller;
    }

    /**
     * @return BController
     */
    public function getControllerInstance()
    {
        return $this->controllerInstance;
    }

    /**
     * catchAll request
     * @return bool
     */
    public function catchAll()
    {
        if (isset($this->app_conf['catch_handle']) && $handle = $this->app_conf['catch_handle']) {
            $this->isEnd = true;
            if ($handle instanceof \Closure) {
                $handle->call($this, $this->request, $this->response);
            } else {
                $this->response->header('Content-Type', 'text/html; charset=UTF-8');
                $this->response->end($handle);
            }
            return true;
        }
        return false;
    }

    /**
     * afterRequest call
     * @param callable $callback
     * @param bool $prepend
     * @return bool
     */
    public function afterRequest(callable $callback, bool $prepend = false)
    {
        return Hook::addHook(Hook::HOOK_AFTER_REQUEST, $callback, $prepend);
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
     * @return SwoolefyException|string
     */
    public function getExceptionClass()
    {
        return BaseServer::getExceptionClass();
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
                $this->onAfterRequest();
                $this->end();
            });
        }
    }

    /**
     *onAfterRequest
     * @return void
     */
    protected function onAfterRequest()
    {
        // call hook callable
        Hook::callHook(Hook::HOOK_AFTER_REQUEST);
    }

    /**
     * request end
     * @return void
     */
    public function end()
    {
        // log handle
        $this->handleLog();
        // remove coroutine instance
        ZFactory::removeInstance();
        // push obj pools
        $this->pushComponentPools();
        // remove App Instance
        Application::removeApp();
        if (!$this->isEnd) {
            @$this->response->end();
        }
    }

    /**
     * setEnd
     * @return void
     */
    public function setEnd()
    {
        $this->isEnd = true;
    }

    /**
     * @return bool
     */
    public function isEnd()
    {
        return $this->isEnd;
    }

}