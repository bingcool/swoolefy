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
    protected static $routes = [];

    /**
     * @param array $callable
     * @param mixed $params
     * @param array $rpcPackHeader
     */
    public function __construct(array $callable, mixed $params, array $rpcPackHeader = [])
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
        list($class, $action) = $this->callable;
        $class = trim(str_replace('\\', DIRECTORY_SEPARATOR, $class), DIRECTORY_SEPARATOR);

        if (!isset(self::$routeCacheFileMap[$class])) {
            if (!$this->checkClass($class)) {
                $this->errorHandle($class, $action, 'error404');
                return false;
            }
        }

        try {
            $class = str_replace(DIRECTORY_SEPARATOR, '\\', $class);
            foreach ($this->beforeHandle as $handle) {
                $result = call_user_func($handle, $this->params);
                if ($result === false) {
                    $errorMsg = sprintf(
                        "Call %s route handle return false, forbidden continue call %s::%s",
                        $class,
                        $class,
                        $action
                    );
                    if (Swfy::isWorkerProcess()) {
                        $this->getErrorHandle()->errorMsg($errorMsg);
                    }
                    return false;
                }
            }

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

            if (method_exists($serviceInstance, $action)) {
                // before Call
                $isContinueAction = $serviceInstance->_beforeAction($action);
                if ($isContinueAction === false) {
                    // end
                    if (Swfy::isWorkerProcess()) {
                        $this->getErrorHandle()->errorMsg("Forbidden access, {$class}::_beforeAction return false ||| " . json_encode($this->params, JSON_UNESCAPED_UNICODE), 403);
                    }
                    return false;
                }
                // next action Call
                $serviceInstance->{$action}($this->params);
                // after Call
                $serviceInstance->_afterAction($action);

                // call after route handle
                foreach ($this->afterHandle as $handle) {
                    try {
                        call_user_func($handle, $this->params);
                    }catch (\Throwable $exception) {
                        // todo
                    }
                }

                // call hook callable
                Hook::callHook(Hook::HOOK_AFTER_REQUEST);

            } else {
                $this->errorHandle($class, $action, 'error500');
                return false;
            }
        } catch (\Throwable $throwable) {
            $exceptionMsg = $throwable->getMessage();
            $errorMsg     = $throwable->getMessage() . ' on ' . $throwable->getFile() . ' on line ' . $throwable->getLine() . ' ||| ' . $class . '::' . $action . ' ||| ' . json_encode($this->params, JSON_UNESCAPED_UNICODE) . '|||' . $throwable->getTraceAsString();

            if (Swfy::isWorkerProcess()) {
                if(SystemEnv::isGraEnv() || SystemEnv::isPrdEnv()) {
                    $errorMsg = $exceptionMsg;
                }
                $this->getErrorHandle()->errorMsg($errorMsg);
            }

            // record exception
            $exceptionClass = Application::getApp()->getExceptionClass();
            $exceptionClass::shutHalt($errorMsg, SwoolefyException::EXCEPTION_ERR, $throwable);
            return false;
        }
    }

    /**
     * @param string $class
     * @param string $action
     * @param string $errorMethod
     * @return bool
     * @throws \Exception
     */
    protected function errorHandle(
        string $class,
        string $action,
        string $errorMethod = 'error404'
    )
    {
        if (Swfy::isWorkerProcess()) {
            $notFoundInstance = $this->getErrorHandle();
            $errorMsg = $notFoundInstance->{$errorMethod}($class, $action);
        }

        $msg = isset($errorMsg['msg']) ? $errorMsg['msg'] : sprintf("Call undefined method %s::%s", $class, $action);
        $exceptionClass = Application::getApp()->getExceptionClass();
        $exceptionClass::shutHalt($msg);
        return true;
    }

    /**
     * @return NotFound
     */
    protected function getErrorHandle()
    {
        $appConf = Swfy::getAppConf();
        $notFoundInstance = new \Swoolefy\Core\NotFound();
        if (isset($appConf['not_found_handler']) && is_string($appConf['not_found_handler'])) {
            $handle = $appConf['not_found_handler'];
            $notFoundInstance = new $handle;
        }
        return $notFoundInstance;
    }

    /**
     * @param int $from_worker_id
     * @param int $task_id
     * @param mixed|null $task
     */
    public function setFromWorkerIdAndTaskId(int $from_worker_id, int $task_id, mixed $task = null)
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
    protected static function getRoutes(): array
    {
        if (empty(self::$routes)) {
            return self::scanRouteFiles(self::$routeRootDir);
        }else {
            return self::$routes;
        }
    }

    /**
     * @param string $routeRootDir
     * @return array
     */
    protected static function scanRouteFiles(string $routeRootDir)
    {
        if (!is_dir($routeRootDir)){
            return [];
        }

        $handle = opendir($routeRootDir);
        while ($file = readdir($handle)) {
            if($file == '.' || $file == '..' ){
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

        return self::$routes;
    }

    /**
     * @param string $uri
     * @return array
     */
    public static function getRouterMapService(string $uri)
    {
        $routerMap = self::getRoutes();
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
    public static function mergeRoutes(array $routes)
    {
        self::$routes = array_merge(self::$routes, $routes);
    }

}