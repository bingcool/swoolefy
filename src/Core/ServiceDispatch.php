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

use Swoolefy\Exception\DispatchException;

class ServiceDispatch extends AppDispatch
{
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
    protected $beforeHandle = [];

    /**
     * @var array|mixed
     */
    protected $afterHandle = [];

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
     * @param array $callable
     * @param mixed $params
     * @param array $rpcPackHeader
     */
    public function __construct(array $callable, $params, array $rpcPackHeader = [])
    {
        parent::__construct();
        $this->callable = $callable;
        $this->params   = $params;
        Application::getApp()->setMixedParams($params);
        Application::getApp()->setRpcPackHeader($rpcPackHeader);
    }

    /**
     * dispatch
     * @return mixed
     * @throws \Exception
     */
    public function dispatch()
    {
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
            $errorMsg     = $throwable->getMessage() . ' on ' . $throwable->getFile() . ' on line ' . $throwable->getLine() . ' ||| ' . $class . '::' . $action . ' ||| ' . json_encode($this->params, JSON_UNESCAPED_UNICODE) . '|||' . $throwable->getTraceAsString();
            if (Swfy::isWorkerProcess()) {
                if (SystemEnv::isGraEnv() || SystemEnv::isPrdEnv()) {
                    $errorMsg = $exceptionMsg;
                }
                $this->getErrorHandle()->errorMsg($errorMsg, -1);
            }
            // record exception
            $exceptionClass = Application::getApp()->getExceptionClass();
            $exceptionClass::shutHalt($errorMsg, SwoolefyException::EXCEPTION_ERR, $throwable);
            return false;
        }
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
     * @param array $beforeHandle
     * @return void
     */
    public function setBeforeHandle(array $beforeHandle = [])
    {
        $this->beforeHandle = $beforeHandle;
    }

    /**
     * @param array $afterHandle
     * @return void
     */
    public function setAfterHandle(array $afterHandle = [])
    {
        $this->afterHandle = $afterHandle;
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
     * @param string $uri
     * @return array
     */
    public static function getRouterMapService(string $uri)
    {
        $routerMap = self::loadRouteFile();
        $uri = trim($uri,DIRECTORY_SEPARATOR);
        if (isset($routerMap[$uri])) {
            $routerHandle = $routerMap[$uri];
            if(!isset($routerHandle['dispatch_route'])) {
                throw new DispatchException('Missing dispatch_route option key');
            }else {
                $dispatchRoute = $routerHandle['dispatch_route'];
            }

            $beforeHandle = [];

            foreach($routerHandle as $alias => $handle) {
                if ($alias != 'dispatch_route') {
                    $beforeHandle[] = $handle;
                    unset($routerHandle[$alias]);
                    continue;
                }
                unset($routerHandle[$alias]);
                break;
            }

            $afterHandle = array_values($routerHandle);

            return [$beforeHandle, $dispatchRoute, $afterHandle];

        }else {
            throw new DispatchException('Missing Dispatch Route Setting');
        }
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
        foreach ($this->beforeHandle as $handle) {
            if ($handle instanceof \Closure) {
                $result = call_user_func($handle, $this->params);
                if ($result === false) {
                    throw new DispatchException('beforeHandle route middle return false, Not Allow Coroutine To Next Middle');
                }
            }else if (is_string($handle) && class_exists($handle)) {
                $handleEntity = new $handle();
                if ($handleEntity instanceof DispatchMiddle) {
                    $handleEntity->handle($this->params);
                }
            }
        }
    }

    /**
     * @return void
     */
    private function handleAfterRouteMiddles()
    {
        foreach ($this->afterHandle as $handle) {
            try {
                if ($handle instanceof \Closure) {
                    call_user_func($handle, $this->params);
                } else if (is_string($handle) && class_exists($handle)) {
                    $handleEntity = new $handle();
                    if ($handleEntity instanceof DispatchMiddle) {
                        $handleEntity->handle($this->params);
                    }
                }
            }catch (\Throwable $exception) {
                // todo
            }
        }

    }

}