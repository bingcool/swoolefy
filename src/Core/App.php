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
use Swoolefy\Http\RequestInput;
use Swoolefy\Http\ResponseOutput;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Core\Coroutine\CoroutineManager;

class App extends \Swoolefy\Core\Component
{
    use \Swoolefy\Core\ServiceTrait;

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
     * @var RequestInput
     */
    protected $requestInput;

    /**
     * @var ResponseOutput
     */
    protected $responseOutput;

    /**
     * $appConf
     * @var array
     */
    public $appConf = [];

    /**
     * $coroutineId
     * @var int
     */
    public $coroutineId;

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
     * @param array $appConf
     * @return void
     */
    public function __construct(array $appConf = [])
    {
        $this->appConf = $appConf;
        $this->coroutineId = CoroutineManager::getInstance()->getCoroutineId();
    }

    /**
     * init
     * @return void
     */
    protected function _init()
    {
        if (isset($this->appConf['session_start']) && $this->appConf['session_start']) {
            if (is_object($this->get('session'))) {
                $this->get('session')->start();
            }
        }
    }

    /**
     * before request application handle
     * @return void
     */
    protected function _bootstrap()
    {
        $conf = BaseServer::getConf();
        if (isset($conf['application_bootstrap'])) {
            $applicationBootstrap = $conf['application_bootstrap'];
            if (class_exists($applicationBootstrap)) {
                $applicationBootstrap::handle($this->requestInput, $this->responseOutput);
            }
        }
    }

    /**
     * run
     * @param Request $request
     * @param Response $response
     * @param mixed $extendData
     * @return void
     * @throws \Throwable
     */
    public function run(Request $request, Response $response, mixed $extendData = null)
    {
        try {
            $this->parseHeaders($request);
            parent::creatObject();
            $this->request  = $request;
            $this->response = $response;
            $this->requestInput = new RequestInput($this->request, $this->response);
            $this->responseOutput = new ResponseOutput($this->request, $this->response);
            Application::setApp($this);
            $this->defer();
            $this->_init();
            $this->_bootstrap();
            if (!$this->catchAll()) {
                $route = new HttpRoute($this->requestInput, $this->responseOutput, $extendData);
                $route->dispatch();
            }
            $this->onAfterRequest();
        } catch (\Throwable $throwable) {
            /** @var SwoolefyException $exceptionHandle */
            $exceptionHandle = $this->getExceptionClass();
            $exceptionHandle::response($this, $throwable);
        } finally {
            if (!$this->isDefer) {
                $this->end();
            }
        }
    }

    /**
     * @param Request $request
     * @return void
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
     * @param array $appConf
     * @return void
     */
    public function setAppConf(array $appConf = [])
    {
        static $isResetAppConf;
        if (!isset($isResetAppConf)) {
            if (!empty($appConf)) {
                $this->appConf = $appConf;
                Swfy::setAppConf($appConf);
                BaseServer::setAppConf($appConf);
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
    public function getControllerInstance(): BController
    {
        return $this->controllerInstance;
    }

    /**
     * @return void
     */
    protected function unsetObjectInstance()
    {
        $this->controllerInstance = null;
    }

    /**
     * @return int
     */
    public function getFd()
    {
        return $this->request->fd;
    }

    /**
     * catchAll request
     * @return bool
     */
    public function catchAll(): bool
    {
        if (isset($this->appConf['catch_handle']) && $handle = $this->appConf['catch_handle']) {
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
    public function afterRequest($callback, bool $prepend = false): bool
    {
        $callback = \Closure::fromCallable($callback);
        return Hook::addHook(Hook::HOOK_AFTER_REQUEST, $callback, $prepend);
    }

    /**
     * @return string
     *
     */
    public function getExceptionClass(): string
    {
        return BaseServer::getExceptionClass();
    }

    /**
     * defer
     * @return void
     */
    protected function defer()
    {
        if (\Swoole\Coroutine::getCid() >= 0) {
            $this->isDefer = true;
            \Swoole\Coroutine::defer(function () {
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
        // unset controllerInstance
        $this->unsetObjectInstance();
        // end request
        if (!$this->isEnd) {
            if($this->response->isWritable()) {
                @$this->response->end();
            }
        }
    }

}