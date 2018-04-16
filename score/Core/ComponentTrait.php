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

trait ComponentTrait {

	/**
	 * $_destroy_components 请求结束后销毁的对象
	 * @var array
	 */
	protected static $_destroy_components = [];

	/**
	 * __set
	 * @param    string  $name 
	 * @param    object  $value
	 * @return   object
	 */
	public function __set($name, $value) {
		if(isset(Swfy::$Di[$name])) {
			return Swfy::$Di[$name];
		}else {
			if(is_array($value)) {
				return self::creatObject($name, $value);
			}
			return false;
		}
	}

	/**
	 * __get
	 * @param    string  $name
	 * @return   object | boolean
	 */
	public function __get($name) {
		if(!isset($this->$name)) {
			if(isset(Swfy::$Di[$name])) {

				if(is_object(Swfy::$Di[$name])) {	
					return Swfy::$Di[$name];
				}else {
					self::clearComponent($name);
					return false;
				}

			}elseif(in_array($name, array_keys(Swfy::$appConfig['components']))) {
				return self::creatObject($name, Swfy::$appConfig['components'][$name]);
			}
			return false;	
		}
	}

	/**
	 * __isset
	 * @param    string  $name
	 * @return   boolean
	 */
	public function __isset($name) {
		return isset($this->$name);
	}

	/**
	 * __unset
	 * @param   string  $name
	 */
	public function __unset($name) {
		unset($this->$name);
	}

	/**
	 * creatObject 创建组件对象
	 * @param    string  $com_alias_name 组件别名
	 * @param    array   $defination     组件定义类
	 * @return   array
	 */
	public function creatObject($com_alias_name = null, array $defination = []) {
		// 动态创建公用组件
		if($com_alias_name) {
			if(!isset(Swfy::$Di[$com_alias_name])) {
				if(isset($defination['class'])) {
					$class = $defination['class'];
					unset($defination['class']);
					$params = [];
					if(isset($defination['constructor'])){
						$params = $defination['constructor'];
						unset($defination['constructor']);
					}
					// 删除延迟创建属性
					if(isset($defination[SWOOLEFY_COM_IS_DELAY])) {
						unset($defination[SWOOLEFY_COM_IS_DELAY]);
					}
					return Swfy::$Di[$com_alias_name] = self::buildInstance($class, $defination, $params, $com_alias_name);
				}else {
					throw new \Exception("component:".$com_alias_name.'must be set class', 1);
				}
				
			}else {
				return Swfy::$Di[$com_alias_name];
			}
			
		}
		// 配置文件初始化创建公用对象
		$coreComponents = self::coreComponents();
		$components = array_merge($coreComponents, Swfy::$appConfig['components']);
		foreach($components as $key=>$component) {
			// 存在直接跳过
			if(isset(Swfy::$Di[$key]) || (isset($component[SWOOLEFY_COM_IS_DELAY]) && $component[SWOOLEFY_COM_IS_DELAY])) {
				continue;
			}
			
			if(isset($component['class']) && $component['class'] != '') {
				$class = $component['class'];
				unset($component['class']);
				$params = [];
				if(isset($component['constructor'])){
					$params = $component['constructor'];
					unset($component['constructor']);
				}
				
				$defination = $component;
				Swfy::$Di[$key] = self::buildInstance($class, $defination, $params, $key);

			}else {
				Swfy::$Di[$key] = false;
			}
		}
		return Swfy::$Di;

	}

	/**
	 * getDependencies
	 * @param    string  $class
	 * @return   array      
	 */
	protected function getDependencies($class)
    {
        $dependencies = [];
        $reflection = new \ReflectionClass($class);

        $constructor = $reflection->getConstructor();
        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $param) {
                if ($param->isDefaultValueAvailable()) {
                    $dependencies[] = $param->getDefaultValue();
                }else {
					$dependencies[] = null;
				}
            }
        }

        return [$reflection, $dependencies];
    }

    /**
     * buildInstance
     * @return  object
     */
	protected function buildInstance($class, $defination, $params, $com_alias_name) {
		list ($reflection, $dependencies) = self::getDependencies($class);

        foreach ($params as $index => $param) {
            $dependencies[$index] = $param;
        }
        if(!$reflection->isInstantiable()) {
            throw new \Exception($reflection->name);
        }
        if(empty($defination)) {
            return $reflection->newInstanceArgs($dependencies);
		}
		
        $object = $reflection->newInstanceArgs($dependencies);
        foreach ($defination as $name => $value) {

        	if($name == SWOOLEFY_COM_IS_DESTROY) {
				if($value) {
					array_push(self::$_destroy_components, $com_alias_name);
				}
        		continue;
        	}

        	if($name == SWOOLEFY_COM_IS_DELAY) {
        		continue;
        	}

        	if($name == SWOOLEFY_COM_FUNC) {
        		call_user_func_array([$object, $defination[$name]], [$defination]);
        		continue;
        	}else if(is_array($object->$name)) {
        		$object->$name = array_merge($object->$name, $value);
        		continue;
        	}

        	$object->$name = $value;
            
        }
        return $object;
	}

	/**
	 * getComponents
	 * @return   array
	 */
	public function getComponents() {
		return Swfy::$Di;
	}

	/**
	 * clearComponent
	 * @param    string|array  $component_alias_name
	 * @return   boolean
	 */
	public function clearComponent($com_alias_name = null) {    
        if(!is_null($com_alias_name) && is_string($com_alias_name)) {
       		unset(Swfy::$Di[$com_alias_name]);
       		return true;
        }elseif(is_array($com_alias_name)) {
       		foreach($com_alias_name as $alias_name) {
       			unset(Swfy::$Di[$alias_name]);
       		}
       		return true;
        }
       return false;
    }

	/**
	 * coreComponents 定义核心组件
	 * @return   array
	 */
	public function coreComponents() {
		return [];
	}
} 