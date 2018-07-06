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

use Swoolefy\Core\Application;

class BaseObject {

	/**
	 * $args 存放协程请求实例的临时变量数据
	 * @var array
	 */
	public $args = [];

	/**
	 * $coroutine_id
	 * @var  string
	 */
	public $coroutine_id;

	/**
     * Returns the fully qualified name of this class.
     * @return string the fully qualified name of this class.
     */
    public static function className() {
        return get_called_class();
    }

    /**
     * setArgs 设置临时变量
     * @param  string  $name
     * @param  boolean
     */
    public function setArgs(string $name, $value) {
    	if($value) {
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
    public function getArgs(string $name) {
    	if(isset($this->args[$name])) {
    		return $this->args[$name];
    	}
    	return null;
    }

	/**
	 * __call
	 * @return   mixed
	 */
	public function __call($action, $args = []) {
		throw new \Exception("Error Processing Request, $action() is not exist！", 1);			
	}

	/**
	 * __callStatic
	 * @return   mixed
	 */
	public static function __callStatic($action, $args = []) {}

	/**
	 * _die 异常终端程序执行
	 * @param    $msg
	 * @param    $code
	 * @return   mixed
	 */
	public static function _die($html='', $msg='') {}

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
		return Application::getApp()->$name;
	}

}