<?php
/**
+----------------------------------------------------------------------
| swoolefy framework bases on swoole extension development, we can use it easily!
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
+----------------------------------------------------------------------
*/

namespace Swoolefy\Core;

use Swoolefy\Core\Log\LogManager;
use Swoolefy\Core\Coroutine\Context;

class BaseObject {

	/**
	 * $coroutine_id
	 * @var  string
	 */
	public $coroutine_id;

    /**
     * @var
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
    public static function className() {
        return get_called_class();
    }

    /**
     * @param \ArrayObject $context
     * @return boolean
     */
    public function setContext(\ArrayObject $context) {
        $this->context = $context;
        return true;
    }

    /**
     * setCid
     * @param mixed $cid
     */
    public function setCid($cid = null) {
        $cid && $this->coroutine_id = $cid;
    }

    /**
     * getCid 
     * @return mixed
     */
    public function getCid() {
        return $this->coroutine_id;
    }

    /**
     * @return bool|null
     */
    public function isSetContext() {
        if($this->context instanceof \ArrayObject) {
            return true;
        }
        return null;
    }

    /**
     * getContext
     * @param mixed
     * @throws \Exception
     */
    public function getContext() {
        if($this->context) {
            return $this->context;
        }else {
            return Context::getContext();
        }
    }

    /**
     * @param string $logAction
     * @param array $log
     */
    public function setLog($logAction, array $log) {
        if(!isset($this->logs[$logAction])) {
            $this->logs[$logAction] = [];
        }
        $this->logs[$logAction][] = $log;
    }

    /**
     * @return array
     */
    public function getLogs() {
        return $this->logs;
    }

    /**
     * handleLog
     * @return void
     */
    public function handleLog() {
        // log send
        if(!empty($actionLogs = $this->getLogs())) {
            foreach($actionLogs as $action => $logs) {
                foreach ($logs as $k => $info) {
                    unset($this->logs[$action][$k]);
                    if(!empty($info)) {
                        LogManager::getInstance()->{$action}(...$info);
                    }
                }
            }
        }
    }

    /**
     * clearLogs
     */
    public function clearLogs() {
        $this->logs = [];
    }

    /**
     * setArgs 设置临时变量
     * @param  string  $name
     * @param  mixed   $value
     * @return boolean
     */
    public function setArgs(string $name, $value) {
    	if($name && $value) {
    		$this->args[$name] = $value;
    		return true;
    	}
    	return false;
    }

    /**
     * getArgs 获取临时变量值
     * @param   string  $name
     * @return  mixed
     */
    public function getArgs(string $name = null) {
    	if(!$name) {
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
	public static function _die($html = '', $msg = '') {}

	/**
	 * __toString 
	 * @return string
	 */
	public function __toString() {
		return get_called_class();
	}	

	/**
	 * 直接获取component组件实例
	 */
	public function __get($name) {
        if(is_object(Application::getApp())) {
            return Application::getApp()->get($name);
        }
        return $name;
	}

}