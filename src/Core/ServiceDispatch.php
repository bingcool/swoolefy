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

use Swoolefy\Core\Coroutine\Context;
use Swoolefy\Exception\DispatchException;

class ServiceDispatch extends AppDispatch
{
    /**
     * 当前协程最近一次 dispatch 失败原因（写入 Context，避免 Worker 内 static 跨协程串号）。
     */
    public const LAST_DISPATCH_ERROR_CONTEXT_KEY = '__service_dispatch_last_error';

    /**
     * $callable 远程调用函数对象类
     * @var array
     */
    protected $callable = [];

    /**
     * $params 远程调用参数
     * @var mixed
     */
    protected $params = null;

    /**
     * @var array
     */
    protected $beforeMiddleware = [];

    /**
     * @var array|mixed
     */
    protected $afterMiddleware = [];

    /**
     * @var int|null
     */
    protected $fromWorkerId = null;

    /**
     * @var int|null
     */
    protected $taskId = null;

    /**
     * @var mixed
     */
    protected $task = null;

    /**
     * @var string
     */
    protected static $routeRootDir = APP_PATH.DIRECTORY_SEPARATOR.'Router';

    /**
     * @var array
     */
    protected static $routeMap = [];

    /**
     * @var array
     */
    protected static $routeCache = [];

    /**
     * @param array $callable
     * @param mixed $params
     * @param array $rpcPackHeader
     */
    public function __construct(array $callable, $params, array $rpcPackHeader = [])
    {
        parent::__construct();
        $this->callable = $callable;
        $this->params   = $params;
        Application::getApp()->setMixedParams($params)->setRpcPackHeader($rpcPackHeader);
    }

    /** 清除当前协程上的 dispatch 失败原因（无 Context 时幂等）。 */
    public static function clearLastDispatchError(): void
    {
        Context::delete(self::LAST_DISPATCH_ERROR_CONTEXT_KEY);
    }

    /** @return string|null 当前协程内最近一次失败原因 */
    public static function getLastDispatchError(): ?string
    {
        $message = Context::get(self::LAST_DISPATCH_ERROR_CONTEXT_KEY);

        return is_string($message) && $message !== '' ? $message : null;
    }

    /**
     * 写入当前协程的 dispatch 失败原因（供 Socket.IO ack 等同协程上层读取）。
     * 空串/null 视为清除；无 Context 容器时静默忽略。
     */
    public static function setLastDispatchError(?string $message): void
    {
        try {
            if ($message === null || $message === '') {
                Context::delete(self::LAST_DISPATCH_ERROR_CONTEXT_KEY);

                return;
            }
            Context::set(self::LAST_DISPATCH_ERROR_CONTEXT_KEY, $message);
        } catch (\Throwable $throwable) {
            // 无协程 Context / Application 时不写进程级状态，避免串号
        }
    }

    /**
     * dispatch
     * @return mixed
     * @throws \Exception
     */
    public function dispatch()
    {
        self::clearLastDispatchError();
        $class = '';
        $action = '';
        try {
            list($class, $action) = $this->callable;
            $class = trim(str_replace('\\', DIRECTORY_SEPARATOR, $class), DIRECTORY_SEPARATOR);
            if (!isset(self::$routeCacheFileMap[$class])) {
                if (!$this->checkClass($class)) {
                    throw new DispatchException("{$class} Not Found!");
                }
            }
            $class = str_replace(DIRECTORY_SEPARATOR, '\\', $class);

            // call before route handle middle
            $this->handleBeforeRouteMiddles();

            /**@var \Swoolefy\Core\Task\TaskService $serviceInstance */
            $serviceInstance = new $class();
            $serviceInstance->setMixedParams($this->params);

            if (isset($this->fromWorkerId) && isset($this->taskId)) {
                $serviceInstance->setFromWorkerId($this->fromWorkerId);
                $serviceInstance->setTaskId($this->taskId);
                if (!empty($this->task)) {
                    $serviceInstance->setTask($this->task);
                }
            }

            // before Call
            $isContinueAction = $serviceInstance->_beforeAction($action);
            if ($isContinueAction === false) {
                throw new DispatchException("_beforeAction forbidden, because return false");
            }
            // next action Call
            $serviceInstance->{$action}($this->params);
            // after Call
            $serviceInstance->_afterAction($action);
            // call after route handle middle
            $this->handleAfterRouteMiddles();
            // call hook callable
            Hook::callHook(Hook::HOOK_AFTER_REQUEST);

        } catch (\Throwable $throwable) {
            $exceptionMsg = $throwable->getMessage();
            self::setLastDispatchError($exceptionMsg);
            $errorMsg     = $throwable->getMessage() . ' on ' . $throwable->getFile() . ' on line ' . $throwable->getLine() . ' ||| ' . $class . '::' . $action . ' ||| ' . json_encode($this->params, JSON_UNESCAPED_UNICODE) . '|||' . $throwable->getTraceAsString();
            if (Swfy::isWorkerProcess()) {
                if (SystemEnv::isGraEnv() || SystemEnv::isPrdEnv()) {
                    $errorMsg = $exceptionMsg;
                }
                // Socket.IO 由协议层 ack/error 回传，避免再推原生 WS error 帧
                if (!self::isCurrentSocketIoDispatch()) {
                    $this->getErrorHandle()->errorMsg($errorMsg, -1);
                }
            }
            // record exception
            $exceptionClass = Application::getApp()->getExceptionClass();
            $exceptionClass::shutHalt($errorMsg, SwoolefyException::EXCEPTION_ERR, $throwable);
            return false;
        }
    }

    /** 当前 Application 上的 WebsocketPacket 是否标记为 Socket.IO 入站 */
    private static function isCurrentSocketIoDispatch(): bool
    {
        $app = Application::getApp();
        if ($app === null || !method_exists($app, 'getWebsocketPacket')) {
            return false;
        }
        $packet = $app->getWebsocketPacket();
        if ($packet === null || !method_exists($packet, 'getMeta')) {
            return false;
        }
        $meta = $packet->getMeta();

        return !empty($meta['socketio']);
    }

    /**
     * @return ExceptionResponseService
     */
    public static function getErrorHandle()
    {
        $appConf = Swfy::getAppConf();
        $exceptionResponseService = new \Swoolefy\Core\ExceptionResponseService();
        if (isset($appConf['exception_response_handler']) && is_string($appConf['exception_response_handler'])) {
            $handle = $appConf['exception_response_handler'];
            $exceptionResponseService = new $handle;
        }
        return $exceptionResponseService;
    }

    /**
     * @param int $from_worker_id
     * @param int $task_id
     * @param mixed|null $task
     */
    public function setFromWorkerIdAndTaskId(int $from_worker_id, int $task_id, $task = null)
    {
        $this->fromWorkerId = $from_worker_id;
        $this->taskId = $task_id;
        $this->task = $task;
    }

    /**
     * checkClass
     * @param string $class
     * @return bool
     */
    public function checkClass(string $class)
    {
        if (isset(self::$routeCacheFileMap[$class])) {
            return true;
        }

        $file = ROOT_PATH . DIRECTORY_SEPARATOR . $class . '.php';
        if (is_file($file)) {
            self::$routeCacheFileMap[$class] = true;
            return true;
        }
        return false;
    }

    /**
     * @param array $beforeMiddleware
     * @return void
     */
    public function setBeforeMiddleware(array $beforeMiddleware = [])
    {
        $this->beforeMiddleware = $beforeMiddleware;
    }

    /**
     * @param array $afterMiddleware
     * @return void
     */
    public function setAfterMiddleware(array $afterMiddleware = [])
    {
        $this->afterMiddleware = $afterMiddleware;
    }

    /**
     * @return array
     */
    public static function loadRouteFile(bool $force = false): array
    {
        if (empty(self::$routeMap) || $force) {
            return self::scanRouteFiles(self::$routeRootDir);
        }else {
            return self::$routeMap;
        }
    }

    /**
     * @param string $routeRootDir
     * @return array
     */
    protected static function scanRouteFiles(string $routeRootDir)
    {
        if (!is_dir($routeRootDir)) {
            return [];
        }

        $handle = opendir($routeRootDir);
        while ($file = readdir($handle)) {
            if ($file == '.' || $file == '..' ) {
                continue;
            }

            $filePath = $routeRootDir.DIRECTORY_SEPARATOR.$file;
            if (is_dir($filePath)) {
                self::scanRouteFiles($filePath);
            }else {
                $fileType = pathinfo($filePath, PATHINFO_EXTENSION);
                if (in_array($fileType, ['php'])) {
                    $routerTemp = include $filePath;
                    self::mergeRoutes($routerTemp);
                }
            }
        }
        closedir($handle);
        return self::$routeMap;
    }

    /**
     * getEndPointMapService 末端服务映射
     *
     * @param string $endPoint
     * @return array
     */
    public static function getEndPointMapService(string $endPoint)
    {
        $endPointMap = self::loadRouteFile();
        $endPoint = trim($endPoint,DIRECTORY_SEPARATOR);

        if (isset(self::$routeCache[$endPoint])) {
            return self::$routeCache[$endPoint];
        }

        if (!isset($endPointMap[$endPoint])) {
            throw new DispatchException('Missing Dispatch EndPoint Path Setting');
        }

        $routerHandleMiddleware = $endPointMap[$endPoint];
        if (!isset($routerHandleMiddleware['dispatch_route'])) {
            throw new DispatchException('Missing dispatch_route option key');
        } else {
            $dispatchRoute = $routerHandleMiddleware['dispatch_route'];
        }

        $beforeMiddleware = $afterMiddleware = [];
        foreach($routerHandleMiddleware as $alias => $handle) {
            if ($alias != 'dispatch_route') {
                if (is_array($handle)) {
                    foreach ($handle as $handleItem) {
                        $beforeMiddleware[] = $handleItem;
                    }
                } else {
                    $beforeMiddleware[] = $handle;
                }
                unset($routerHandleMiddleware[$alias]);
                continue;
            }
            unset($routerHandleMiddleware[$alias]);
            break;
        }

        $afterMiddlewareTemp = array_values($routerHandleMiddleware);
        foreach ($afterMiddlewareTemp as $afterMiddlewareItem) {
            if (is_array($afterMiddlewareItem)) {
                foreach ($afterMiddlewareItem as $afterMiddlewareEvery) {
                    $afterMiddleware[] = $afterMiddlewareEvery;
                }
            } else {
                $afterMiddleware[] = $afterMiddlewareItem;
            }
        }

        $routeItems = [$beforeMiddleware, $dispatchRoute, $afterMiddleware];
        self::$routeCache[$endPoint] = $routeItems;
        return $routeItems;
    }

    /**
     * @param array $routes
     * @return void
     */
    protected static function mergeRoutes(array $routes)
    {
        self::$routeMap = array_merge(self::$routeMap, $routes);
    }

    /**
     * @return false|void
     */
    private function handleBeforeRouteMiddles()
    {
        foreach ($this->beforeMiddleware as $middleware) {
            if ($middleware instanceof \Closure) {
                $result = call_user_func($middleware, $this->params);
                if ($result === false) {
                    throw new DispatchException('beforeHandle route middle return false, Not Allow Coroutine To Next Middle');
                }
            } else if (is_string($middleware) && class_exists($middleware)) {
                $middlewareEntity = new $middleware();
                if ($middlewareEntity instanceof DispatchMiddle) {
                    $middlewareEntity->handle($this->params);
                }
            }
        }
    }

    /**
     * @return void
     */
    private function handleAfterRouteMiddles()
    {
        foreach ($this->afterMiddleware as $middleware) {
            try {
                if ($middleware instanceof \Closure) {
                    call_user_func($middleware, $this->params);
                } else if (is_string($middleware) && class_exists($middleware)) {
                    $middlewareEntity = new $middleware();
                    if ($middlewareEntity instanceof DispatchMiddle) {
                        $middlewareEntity->handle($this->params);
                    }
                }
            }catch (\Throwable $exception) {
                // todo
            }
        }

    }

}