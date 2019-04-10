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

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Struct;
use Swoolefy\Core\BaseObject;
use Swoolefy\Core\Application;

class SModel extends BaseObject {

	/**
	 * $config 应用层配置
	 * @var null
	 */
	public $config = null;

	/**
	 * $struct 数据结构对象
	 * @var null
	 */
	protected $struct = null;

	/**
	 * __construct 初始化函数
	 */
	public function __construct() {
		$this->config = Swfy::getAppConf();
		$this->struct = new Struct();
		if(\co::getCid() > 0) {
			defer(function() {
		    	$this->destruct();
        	});
		}
	}

	/**
	 * setStruct 
	 * @param   string  $property
	 * @param   mixed   $value
	 */
	public function setStruct(string $property, $value = null) {
		$this->struct->set($property, $value);
	}

	/**
	 * setMStruct 批量设置
	 * @param    array  $array
	 * @return   boolean
	 */
	public function setMStruct(array $array = []) {
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
	 * @param    string  $property
	 * @param    mixed   $default
	 * @return   mixed
	 */
	public function getStruct(string $property, $default = null) {
		return $this->struct->get($property, $default);
	}

	/**
	 * getAllStruct 获取所有数据结构属性值
	 * @return   mixed
	 */
	public function getMStruct() {
		return $this->struct->getPublicProperties();
	}

	/**
	 * beforeAction 在处理实际action之前执行
	 * @return   mixed
	 */
	public function _beforeModel() {
		return true;
	}

	/**
	 * afterAction 在返回数据之前执行
	 * @return   mixed
	 */
	public function _afterModel() {
		return true;
	}

	/**
	 * __destruct 对象销毁前处理一些静态变量
	 * @param    
	 */
	public function destruct() {
		static::_afterModel();
	}

	use \Swoolefy\Core\ServiceTrait;
}