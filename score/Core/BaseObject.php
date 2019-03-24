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
	 * $coroutine_id
	 * @var  string
	 */
	public $coroutine_id;

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
    	if(isset($name) && isset($this->args[$name])) {
    		return $this->args[$name];
    	}
    	return null;
    }

	/**
	 * __call
     * @throws  \Exception
	 * @return   mixed
	 */
	public function __call($action, $args = []) {
		throw new \Exception("Error Processing Request, {$action}() is undefined", 1);
	}

	/**
	 * __callStatic
	 * @return   void
	 */
	public static function __callStatic($action, $args = []) {}

	/**
	 * _die 异常终端程序执行
	 * @param   string $html
	 * @param   string $msg
	 * @return  void
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
		return Application::getApp()->$name;
	}

}