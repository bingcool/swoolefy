<?php
namespace Swoolefy\Core;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Object;
use Swoolefy\Core\Struct;
use Swoolefy\Core\Application;

class SModel extends Object {

	/**
	 * $config 应用层配置
	 * @var null
	 */
	public $config = null;

	/**
	 * $struct 数据结构对象
	 * @var null
	 */
	public $struct = null;

	/**
	 * __construct 初始化函数
	 */
	public function __construct() {
		$this->config = Application::$app->config;
		// 数据结构模型对象
		$this->struct = new Struct();
	}

	/**
	 * setStruct 
	 * @param   string  $property
	 * @param   mixed   $value
	 */
	public function setStruct($property, $value=null) {
		$this->struct->set($property, $value);
	}

	/**
	 * MsetStruct 批量设置 
	 * @param    array  $array
	 * @return   boolean
	 */
	public function MsetStruct($array) {
        if(!is_array($array) || empty($array)) {
            return false;
        }

        foreach($array as $property=>$value) {
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
	public function getStruct($property, $default = null) {
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
	public function _beforeAction() {
		return true;
	}

	/**
	 * afterAction 在返回数据之前执行
	 * @return   mixed
	 */
	public function _afterAction() {
		return true;
	}

	/**
	 * __destruct 对象销毁前处理一些静态变量
	 * @param    
	 */
	public function __destruct() {
		static::_afterAction();
	}

	use \Swoolefy\Core\ComponentTrait, \Swoolefy\Core\ServiceTrait;
}