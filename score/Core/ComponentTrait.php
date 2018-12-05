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
	 * $container
	 * @var array
	 */
	protected $container = [];

	/**
	 * $pools_component 需要创建进程池的组件，一般是mysql，或者redis
	 * @var array
	 */
	protected $component_pools = [];

	/**
	 * creatObject 创建组件对象
	 * @param    string  $com_alias_name 组件别名
	 * @param    array   $defination     组件定义类
	 * @return   array
	 */

	public function creatObject(string $com_alias_name = null, array $defination = []) {
		// 动态创建公用组件
		if($com_alias_name) {
			if(!isset($this->container[$com_alias_name])) {
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
					return $this->container[$com_alias_name] = $this->buildInstance($class, $defination, $params, $com_alias_name);
				}else {
					throw new \Exception("component:".$com_alias_name.'must be set class', 1);
				}

			}else {
				return $this->container[$com_alias_name];
			}

		}
		// 配置文件初始化创建公用对象
		$coreComponents = $this->coreComponents();
		$components = array_merge($coreComponents, Swfy::getAppConf()['components']);
		foreach($components as $com_name=>$component) {

			if($this->isSetOpenPoolsOfComponent($component)) {
				$this->setOpenPoolsOfComponent($com_name);
				continue;
			}

			// 存在直接跳过
			if(isset($this->container[$com_name]) || (isset($component[SWOOLEFY_COM_IS_DELAY]) && $component[SWOOLEFY_COM_IS_DELAY])) {
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
				$this->container[$com_name] = $this->buildInstance($class, $defination, $params, $com_name);
			}else {
				$this->container[$com_name] = false;
			}
		}
		return $this->container;

	}

	/**
	 * getDependencies
	 * @param    string  $class
	 * @return   array
	 */
	protected function getDependencies($class) {
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

        // 回调必须设置在配置的最后
        if(isset($defination[SWOOLEFY_COM_FUNC])) {
        	$keys = array_keys($defination);
        	if(end($keys) != SWOOLEFY_COM_FUNC) {
        		$func = $defination[SWOOLEFY_COM_FUNC];
        		unset($defination[SWOOLEFY_COM_FUNC]);
        		// 设置在数组末尾
        		$defination[SWOOLEFY_COM_FUNC] = $func;
        	}
        	unset($keys);
        }

        foreach ($defination as $name => $value) {
        	if($name == SWOOLEFY_COM_IS_DELAY) {
        		continue;
        	}

        	if($name == SWOOLEFY_COM_FUNC) {
        		if(is_string($defination[$name]) && method_exists($object, $defination[$name])) {
        			call_user_func_array([$object, $defination[$name]], [$defination]);
        		}else if($defination[$name] instanceof \Closure) {
        			// call_user_func_array($defination[$name]->bindTo($object, get_class($object)), [$defination]);
        			($defination[$name])->call($object, $defination);
        		}else {
        			throw new \Exception("$com_alias_name component's config item 'func' is not Closure or $com_alias_name instance is not exists the method!");
        		}
        		continue;
        	}else if(isset($object->$name) && @is_array($object->$name)) {
        		$object->$name = array_merge($object->$name, $value);
        		continue;
        	}

        	$object->$name = $value;

        }
        return $object;
	}

	/**
	 * getComponents
	 * @param    string $com_alias_name
	 * @return   mixed;
	 */
	public function getComponents(string $com_alias_name = null) {
		if($com_alias_name && isset($this->container[$com_alias_name])) {
			return $this->container[$com_alias_name];
		}

		return  $this->container;
	}

	/**
	 * clearComponent
	 * @param    string|array  $component_alias_name
	 * @return   boolean
	 */
	public function clearComponent($com_alias_name = null) {
        if(!is_null($com_alias_name) && is_string($com_alias_name)) {
	        $com_alias_name = (array)$com_alias_name;
        }else if(is_array($com_alias_name)) {
        	$com_alias_name = array_unique($com_alias_name);
        }else {
        	return false;
        }
        foreach($com_alias_name as $alias_name) {
        	unset($this->container[$alias_name]);
       	}
        return true;
    }

	/**
	 * coreComponents 定义核心组件
	 * @return   array
	 */
	public function coreComponents() {
		return [];
	}

	/**
	 * __set
	 * @param    string  $name
	 * @param    object  $value
	 * @return   object
	 */
	public function __set($name, $value) {
		if(isset($this->container[$name])) {
			return $this->container[$name];
		}else {
			if(is_array($value)) {
				return $this->creatObject($name, $value);
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
        $components = Swfy::getAppConf()['components'];
		if(!isset($this->$name)) {
			if(isset($this->container[$name])) {
				if(is_object($this->container[$name])) {
					return $this->container[$name];
				}else {
					$this->clearComponent($name);
					return false;
				}
			}else if(in_array($name, array_keys($components))) {
				// mysql,redis进程池中直接赋值
				if(in_array($name, $this->component_pools)) {
					$this->container[$name] = \Swoolefy\Core\Pools::getInstance()->getObj($name);
					// 如果没有设置进程池处理实例，则降级到创建实例模式
					if(is_object($this->container[$name])) {
						return $this->container[$name];
					}
				}
				return $this->creatObject($name, $components[$name]);
			}
			return false;
		}
	}

    /**
     * isSetOpenPoolsOfComponent 组件是否开启pools
     */
	private function isSetOpenPoolsOfComponent(array &$component) {
        if(isset($component[SWOOLEFY_ENABLE_POOLS]) && isset($component[SWOOLEFY_POOLS_NUM])) {
           return true;
        }
        return false;
    }

    /**
     * setComponentPools 设置记录启用pools的组件，一般在自定义进程做db,redis的进程池需要用到，慎用此函数
     * @param string|null $com_alias_name
     */
	public function setOpenPoolsOfComponent(string $com_alias_name = null) {
        if($com_alias_name) {
            if(!in_array($com_alias_name, $this->component_pools)) {
                array_push($this->component_pools, $com_alias_name);
            }
        }
    }

    /**
     * getOpenPoolsOfComponent 获取启用pools的组件名称
     */
    public function getOpenPoolsOfComponent() {
        return $this->component_pools;
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
} 