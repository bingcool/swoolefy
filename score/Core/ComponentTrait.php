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

use Swoolefy\Core\BaseServer;

trait ComponentTrait {

	/**
	 * $container
	 * @var array
	 */
	protected $container = [];

	/**
	 * $pools_component 需要创建进程池的组件
	 * @var array
	 */
	protected $component_pools = [];

	/**
	 * $component_pools_obj_ids 进程池的组件对象id,区分非来自进程池的组件,因为来自进程的组件不能push到进程池，否则会污染
	 * @var array
	 */
	protected $component_pools_obj_ids = [];

	/**
	 * creatObject 创建组件对象
	 * @param    string  $com_alias_name 组件别名
	 * @param    mixed   $defination     组件定义类
     * @throws   mixed
	 * @return   mixed
	 */

	public function creatObject(string $com_alias_name = null, $defination = []) {
		// 动态创建公用组件
		if($com_alias_name) {
			if(!isset($this->container[$com_alias_name])) {
				if(is_array($defination) && isset($defination['class'])) {
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
				}else if($defination instanceof \Closure) {
                    return $this->container[$com_alias_name] = call_user_func($defination, $com_alias_name);
				}else {
                    throw new \Exception("component:".$com_alias_name.'must be set class', 1);
                }

			}else {
				return $this->container[$com_alias_name];
			}

		}
		// 配置文件初始化创建公用对象
		$coreComponents = $this->coreComponents();
		$components = array_merge($coreComponents, BaseServer::getAppConf()['components']);

		foreach($components as $com_name=>$component) {

		    if($component instanceof \Closure || isset($this->container[$com_name])) {
		        // delay create
                continue;
            }

			if(isset($component[SWOOLEFY_COM_IS_DELAY])) {
                $is_delay = filter_var($component[SWOOLEFY_COM_IS_DELAY], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if($is_delay === true) {
                    continue;
                }
			}else {
                continue;
            }

			if(isset($component['class']) && !empty($component['class'])) {
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
     * @throws  \Exception
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
        			($defination[$name])->call($object, $defination);
        		}else {
        			throw new \Exception("{$com_alias_name} component's config item 'func' is not Closure or {$com_alias_name} instance is not exists the method!");
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
        	if(isset($this->container[$alias_name])) {
        		unset($this->container[$alias_name]);
        	}
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
	 * __set
	 * @param    string  $name
	 * @param    mixed  $value
	 * @return   mixed
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
	 * @return   mixed
	 */
	public function __get($name) {
		$AppConf = BaseServer::getAppConf();
        $components = $AppConf['components'];
        if(empty($this->component_pools)) {
        	if(isset($AppConf['enable_component_pools']) && is_array($AppConf['enable_component_pools']) && !empty($AppConf['enable_component_pools'])) {
        		$this->component_pools = $AppConf['enable_component_pools'];
        	}
        }
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
				    $poolHandler = \Swoolefy\Core\Coroutine\CoroutinePools::getInstance()->getPool($name);
					if(is_object($poolHandler)) {
                        $this->container[$name] = $poolHandler->fetchObj();
                    }
					// 如果没有设置进程池处理实例，则降级到创建实例模式
					if(isset($this->container[$name]) && is_object($this->container[$name])) {
						$obj_id = spl_object_id($this->container[$name]);
						if(!in_array($obj_id, $this->component_pools_obj_ids)) {
							array_push($this->component_pools_obj_ids, $obj_id);
						}
						return $this->container[$name];
					}
				}
				return $this->creatObject($name, $components[$name]);
			}
			return false;
		}
	}

	/**
	 * __unset
	 * @param   string  $name
	 */
	public function __unset($name) {
		unset($this->$name);
	}
} 