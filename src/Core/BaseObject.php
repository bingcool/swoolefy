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

use ArrayObject;
use Swoole\Coroutine;
use Swoolefy\Core\Log\LogManager;

class BaseObject
{

    /**
     * $coroutine_id
     * @var string
     */
    public $coroutine_id;

    /**
     * @var \ArrayObject
     */
    protected $context;

    /**
     * @var array
     */
    protected $logs = [];

    /**
     * $args 存放协程请求实例的临时变量数据
     * @var array
     */
    protected $args = [];

    /**
     * className Returns the fully qualified name of this class.
     * @return string the fully qualified name of this class.
     */
    public static function className()
    {
        return get_called_class();
    }

    /**
     * @param \ArrayObject $context
     * @return bool
     */
    public function setContext(\ArrayObject $context)
    {
        $this->context = $context;
        return true;
    }

    /**
     * setCid
     * @param mixed $cid
     */
    public function setCid($cid = null)
    {
        $cid && $this->coroutine_id = $cid;
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
     * @return bool
     */
    public function isSetContext()
    {
        if ($this->context instanceof \ArrayObject) {
            return true;
        }
        return false;
    }

    /**
     * @return ArrayObject
     */
    public function getContext()
    {
        if ($this->context) {
            $context = $this->context;
        } else if (Coroutine::getCid() > 0) {
            $context = Coroutine::getContext();
        } else {
            $context = new ArrayObject();
            $context->setFlags(ArrayObject::STD_PROP_LIST | ArrayObject::ARRAY_AS_PROPS);
            $this->context = $context;
        }

        return $context;
    }

    /**
     * @param string $type
     * @param string $logAction
     * @param array $log
     * @return void
     */
    public function setLog(string $type, string $logAction, array $log)
    {
        if (!isset($this->logs[$type][$logAction])) {
            $this->logs[$type][$logAction] = [];
        }
        $this->logs[$type][$logAction][] = $log;
    }

    /**
     * @return array
     */
    public function getLogs()
    {
        return $this->logs;
    }

    /**
     * handleLog
     * @return void
     */
    protected function handleLog()
    {
        // log send
        if (!empty($actionLogs = $this->getLogs())) {
            foreach ($actionLogs as $type => $logs) {
                $logger = LogManager::getInstance()->getLogger($type);
                if (!is_object($logger)) {
                    $this->clearLogs();
                    break;
                }
                foreach ($logs as $action => $logArr) {
                    foreach ($logArr as $k => $info) {
                        unset($this->logs[$type][$action][$k]);
                        if (!empty($info)) {
                            $logger->{$action}(...$info);
                        }
                    }

                }
            }
        }
    }

    /**
     * clearLogs
     * @return void
     */
    public function clearLogs()
    {
        $this->logs = [];
    }

    /**
     * setArgs 设置临时变量
     * @param string $name
     * @param mixed $value
     * @return bool
     */
    public function setArgs(string $name, $value)
    {
        if ($name && $value) {
            $this->args[$name] = $value;
            return true;
        }
        return false;
    }

    /**
     * getArgs 获取临时变量值
     * @param string $name
     * @return mixed
     */
    public function getArgs(string $name = null)
    {
        if (!$name) {
            return $this->args;
        }
        return $this->args[$name] ?? null;
    }

    /**
     * _die 异常终端程序执行
     * @param string $html
     * @param string $msg
     * @return void
     */
    public static function _die($html = '', $msg = '')
    {
    }

    /**
     * __toString
     * @return string
     */
    public function __toString()
    {
        return get_called_class();
    }

    /**
     * get component
     * @return mixed
     */
    public function __get($name)
    {
        if (is_object(Application::getApp())) {
            return Application::getApp()->get($name);
        }
        return false;
    }

}