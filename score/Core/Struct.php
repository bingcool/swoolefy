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

class Struct {

	/**
	 * __construct 
	 * @param    
	 */
	public function __construct() {}

	/**
	 * get 获取数据结构属性值
	 * @param   string  $property
	 * @param   mixed   $default 
	 * @return  string
	 */
	public function get($property, $default = null) {
		if(isset($this->{$property}))
		{
			return $this->{$property};
		}
		return $default;
	}

	/**
	 * getProperties 获取所有设置数据结构属性
	 * @param    boolean  $public
	 * @return   array
	 */
	public function getProperties($public = true) {
		$vars = get_object_vars($this);
		
		if($public) {
			foreach ($vars as $k => $v)
			{
				if ('_' == substr($k, 0, 1))
				{
					unset($vars[$k]);
				}
			}
		}
		return $vars;
	}

	/**
	 * set 设置数据结构属性值
	 * @param   string  $property
	 * @param   mixed   $value
     * @return  mixed
	 */
	public function set($property, $value = null) {
		$previous = isset($this->{$property}) ? $this->{$property} : null;
		$this->{$property} = $value;
		return $previous;
	}

	/**
	 * setProperties 批量设置
	 * @param  array  $properties
	 */
	public function setProperties($properties) {
		$properties = (array)$properties;
		if(is_array($properties)) {
			foreach ($properties as $k => $v) {
				$this->$k = $v;
			}
		}
	}

	/**
	 * getPublicProperties 获取设置的公有属性值
	 * @return   array
	 */
	public function getPublicProperties() {
		return $this->getProperties();
	}

}