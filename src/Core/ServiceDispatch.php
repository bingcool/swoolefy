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
     * @param $callable
     * @param $params
     * @param array $rpc_pack_header
     */
    public function __construct($callable, $params, $rpc_pack_header = [])
    {
        parent::__construct();
        $this->callable = $callable;
        $this->params = $params;
        Application::getApp()->setMixedParams($params);
        Application::getApp()->setRpcPackHeader($rpc_pack_header);
    }

    /**
     * dispatch
     * @return mixed
     * @throws \Exception
     */
    public function dispatch()
    {
        list($class, $action) = $this->callable;
        $class = trim(str_replace('\\', '/', $class), '/');

        if (!isset(self::$routeCacheFileMap[$class])) {
            if (!$this->checkClass($class)) {
                $this->errorHandle($class, $action, 'error404');
                return false;
            }
        }

        $class = str_replace('/', '\\', $class);
        /**@var \Swoolefy\Core\Task\TaskService $serviceInstance */
        $serviceInstance = new $class();
        $serviceInstance->setMixedParams($this->params);
        if (isset($this->from_worker_id) && isset($this->task_id)) {
            $serviceInstance->setFromWorkerId($this->from_worker_id);
            $serviceInstance->setTaskId($this->task_id);
            if (!empty($this->task)) {
                $serviceInstance->setTask($this->task);
            }
        }

        try {
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
                $serviceInstance->$action($this->params);
                // after Call
                $serviceInstance->_afterAction($action);
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
     * @param $class
     * @param $action
     * @param string $errorMethod
     * @return bool
     * @throws \Exception
     */
    protected function errorHandle($class, $action, $errorMethod = 'error404')
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
        $app_conf = Swfy::getAppConf();
        $notFoundInstance = new \Swoolefy\Core\NotFound();
        if (isset($app_conf['not_found_handler']) && is_string($app_conf['not_found_handler'])) {
            $handle = $app_conf['not_found_handler'];
            $notFoundInstance = new $handle;
        }
        return $notFoundInstance;
    }

    /**
     * @param int $from_worker_id
     * @param int $task_id
     * @param mixed|null $task
     */
    public function setFromWorkerIdAndTaskId(int $from_worker_id, int $task_id, $task = null)
    {
        $this->from_worker_id = $from_worker_id;
        $this->task_id = $task_id;
        $this->task = $task;
    }

    /**
     * checkClass
     * @param string $class
     * @return bool
     */
    public function checkClass($class)
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

}