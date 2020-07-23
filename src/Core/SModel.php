<?php
/**
+----------------------------------------------------------------------
| swoolefy framework bases on swoole extension development, we can use it easily!
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| @see https://github.com/bingcool/swoolefy
+----------------------------------------------------------------------
*/

namespace Swoolefy\Core;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Struct;
use Swoolefy\Core\BaseObject;
use Swoolefy\Core\Application;
use Swoolefy\Core\Coroutine\CoroutineManager;

class SModel extends BaseObject {

    use \Swoolefy\Core\ServiceTrait;

    /**
	 * $app_conf 应用层配置
	 * @var array
	 */
	public $app_conf = null;

	/**
	 * $struct 数据结构对象
	 * @var Struct
	 */
	protected $struct = null;

	/**
	 * __construct 初始化函数
	 */
	public function __construct() {
		$this->app_conf = Swfy::getAppConf();
		$this->struct = new Struct();
		$this->coroutine_id = CoroutineManager::getInstance()->getCoroutineId();
	}

	/**
	 * setStruct 
	 * @param string $property
	 * @param mixed $value
	 */
	public function setStruct(string $property, $value = null) {
		$this->struct->set($property, $value);
	}

	/**
	 * setMultiStruct 批量设置
	 * @param array $array
	 * @return boolean
	 */
	public function setMultiStruct(array $array = []) {
        if(!is_array($array) || empty($array)) {
            return false;
        }
        foreach($array as $property => $value) {
            $this->struct->set($property, $value);
        }
        return true;
    }

	/**
	 * getStruct 
	 * @param string $property
	 * @param mixed  $default
	 * @return mixed
	 */
	public function getStruct(string $property, $default = null) {
		return $this->struct->get($property, $default);
	}

	/**
	 * getAllStruct 获取所有数据结构属性值
	 * @return mixed
	 */
	public function getAllStruct() {
		return $this->struct->getPublicProperties();
	}

	/**
	 * beforeAction 在处理之前执行
	 * @return boolean
	 */
	public function _beforeModel() {
		return true;
	}

	/**
	 * afterAction 在返回数据之前执行
	 * @return boolean
	 */
	public function _afterModel() {
		return true;
	}

	/**
	 * destruct 对象销毁前处理一些静态变量
	 */
	public function __destruct() {
		static::_afterModel();
	}
}