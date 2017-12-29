<?php
namespace Swoolefy\Core;

class Component extends \Swoolefy\Core\Object {

	/**
	 * $components
	 * @var array
	 */
	public static $_components = [];

	/**
	 * __set
	 * @param    string  $name 
	 * @param    object  $value
	 * @return   object
	 */
	public function __set($name,$value) {
		if(!isset($name) && $value !='') {
			static::$_components[$name] = $value;
		}
	}

	/**
	 * __get
	 * @param    string  $name
	 * @return   object | boolean
	 */
	public function __get($name) {
		if(!isset($this->$name)) {
			if(isset(static::$_components[$name])) {
				if(is_object(static::$_components[$name])) {
					// 以下组件需要做特殊处理,redis的timeout设置为0
					switch($name) {
						case 'redis': 
						case 'redis_session':
							$isConnected = static::$_components[$name]->isConnected();
							if(!$isConnected) {
								self::clearComponent($name);
								self::creatObject($name, $this->config['components'][$name]);
							}
						break;
					}

					return static::$_components[$name];
					
				}else {
					self::clearComponent($name);
					return false;
				}
			}elseif(in_array($name, array_keys($this->config['components']))) {
				return self::creatObject($name, $this->config['components'][$name]);
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
	public function creatObject($com_alias_name=null,array $defination=[]) {
		// 动态创建公用组件
		if($com_alias_name) {
			if(!isset(static::$_components[$com_alias_name])) {
				if(isset($defination['class'])) {
					$class = $defination['class'];
					unset($defination['class']);
					$params = [];
					if(isset($defination['constructor'])){
						$params = $defination['constructor'];
						unset($defination['constructor']);
					}
					return static::$_components[$com_alias_name] = Swfy::$Di[$com_alias_name] = $this->buildInstance($class, $defination, $params);
				}else {
					throw new \Exception("component:".$com_alias_name.'must be set class', 1);
				}
				
			}else {
				return static::$_components[$com_alias_name];
			}
			
		}
		// 配置文件初始化创建公用对象
		$coreComponents = $this->coreComponents();
		$components = array_merge($coreComponents,$this->config['components']);
		foreach($components as $key=>$component) {
			// 如果存在直接跳过，下一个
			if(isset(static::$_components[$key]) || (isset($component['isDelay']) && $component['isDelay'])) {
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
				static::$_components[$key] = Swfy::$Di[$key] = $this->buildInstance($class, $defination, $params);
			}else {
				static::$_components[$key] = Swfy::$Di[$key] = false;
			}
		}
		return static::$_components;

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
	protected function buildInstance($class, $defination, $params) {
		list ($reflection, $dependencies) = $this->getDependencies($class);

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
        	if(is_array($object->$name)) {
        		$object->$name = array_merge($object->$name,$value);
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
		return static::$_components;
	}

	/**
	 * clearComponent
	 * @param    string|array  $component_alias_name
	 * @return   boolean
	 */
	public function clearComponent($com_alias_name=null) {    
        if(!is_null($com_alias_name) && is_string($com_alias_name)) {
       		unset(static::$_components[$com_alias_name], Swfy::$Di[$com_alias_name]);
       		return true;
        }elseif(is_array($com_alias_name)) {
       		foreach($com_alias_name as $alias_name) {
       			unset(static::$_components[$alias_name], Swfy::$Di[$alias_name]);
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