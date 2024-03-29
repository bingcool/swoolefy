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
     * $coroutineId
     * @var int
     */
    public $coroutineId = null;

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
    public static function className(): string
    {
        return get_called_class();
    }

    /**
     * @param \ArrayObject $context
     * @return bool
     */
    public function setContext(\ArrayObject $context): bool
    {
        $this->context = $context;
        return true;
    }

    /**
     * setCid
     * @param int $coroutineId
     * @return bool
     */
    public function setCid(int $coroutineId): bool
    {
        if($coroutineId >=0 ) {
            $this->coroutineId = $coroutineId;
        }
        return true;
    }

    /**
     * getCid
     * @return int
     */
    public function getCid(): int
    {
        return $this->coroutineId ?? \Swoole\Coroutine::getCid();
    }

    /**
     * @return bool
     */
    public function isSetContext(): bool
    {
        if ($this->context instanceof \ArrayObject) {
            return true;
        }
        return false;
    }

    /**
     * @return ArrayObject
     */
    public function getContext(): ArrayObject
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
    public function getLogs(): array
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
                            try {
                                $logger->{$action}(...$info);
                            }catch (\Throwable $exception) {

                            }
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
    public function setArgs(string $name, $value): bool
    {
        if ($name && $value) {
            $this->args[$name] = $value;
            return true;
        }
        return false;
    }

    /**
     * getArgs 获取临时变量值
     * @param string|null $name
     * @return mixed
     */
    public function getArgs(?string $name = null)
    {
        if (!$name) {
            return $this->args;
        }
        return $this->args[$name] ?? null;
    }

    /**
     * __toString
     * @return string
     */
    public function __toString(): string
    {
        return get_called_class();
    }

    /**
     * get component
     * @param string $name
     * @return object|null
     */
    public function __get(string $name)
    {
        if (is_object(Application::getApp())) {
            return Application::getApp()->get($name);
        }
        return null;
    }

}