<?php
namespace Swoolefy\Core;

class Component {

	/**
	 * $components
	 * @var array
	 */
	public static $_components = [];

	/**
	 * __set
	 * @param    $name 
	 * @param    $value
	 * @return   
	 */
	public function __set($name,$value) {
		if(!isset($name) && $value !='') {
			static::$_components[$name] = $value;
		}
	}

	/**
	 * __get
	 * @param    $name
	 * @return   
	 */
	public function __get($name) {
		if(!isset($this->$name)) {
			if(isset(static::$_components[$name])) {
				if(is_object(static::$_components[$name])) {
					return static::$_components[$name];
				}
				return false;	
			}	
		}
	}

	/**
	 * creatObject 创建组件对象
	 * @param    $type
	 * @param    $defination
	 * @return   array
	 */
	public function creatObject($type=null,array $defination=[]) {
		// 动态创建公用组件
		if($type) {
			$com_name = $type;
			if(!isset(static::$_components[$com_name])) {
				if(isset($defination['class'])) {
					$class = $defination['class'];
					unset($defination['class']);
					$params = [];
					if(isset($defination['constructor'])){
						$params = $defination['constructor'];
						unset($defination['constructor']);
					}
					return static::$_components[$com_name] = Swfy::$Di[$com_name] = $this->build($class, $defination, $params);
				}else {
					throw new \Exception("component:".$com_name.'must be set class', 1);
				}
				
			}else {
				return static::$_components[$com_name];
			}
			
		}
		// 配置文件初始化创建公用对象
		$coreComponents = $this->coreComponents();
		$components = array_merge($coreComponents,$this->config['components']);
		foreach($components as $key=>$component) {
			// 如果存在直接跳过，下一个
			if(isset(static::$_components[$key])) {
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
				static::$_components[$key] = Swfy::$Di[$key] = $this->build($class, $defination, $params);
			}else {
				throw new \Exception("component:".$key.'must be set class', 1);
				  
			}
		}
		return static::$_components;

	}

	/**
	 * getDependencies
	 * @param    $class
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
                }
            }
        }

        return [$reflection, $dependencies];
    }

    /**
     * build
     * @return  object
     */
	public function build($class, $config, $params) {
		list ($reflection, $dependencies) = $this->getDependencies($class);

        foreach ($params as $index => $param) {
            $dependencies[$index] = $param;
        }
        if(!$reflection->isInstantiable()) {
            throw new \Exception($reflection->name);
        }
        if(empty($config)) {
            return $reflection->newInstanceArgs($dependencies);
        }

        $object = $reflection->newInstanceArgs($dependencies);
        foreach ($config as $name => $value) {
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
	 * coreComponents 定义核心组件
	 * @return   array
	 */
	public function coreComponents() {
		return [];
	}
}