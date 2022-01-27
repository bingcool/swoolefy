<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

namespace Swoolefy\Core;

trait ComponentTrait
{

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
     * @param string $com_alias_name 组件别名
     * @param mixed $definition 组件定义类
     * @return   mixed
     * @throws   mixed
     */
    public function creatObject(string $com_alias_name = null, $definition = [])
    {
        // 动态创建公用组件
        if ($com_alias_name) {
            if (!isset($this->container[$com_alias_name]) || !is_object($this->container[$com_alias_name])) {
                if (is_array($definition) && isset($definition['class'])) {
                    $class = $definition['class'];
                    unset($definition['class']);
                    $params = [];
                    if (isset($definition['constructor'])) {
                        $params = $definition['constructor'];
                        unset($definition['constructor']);
                    }
                    if (isset($definition[SWOOLEFY_COM_IS_DELAY])) {
                        unset($definition[SWOOLEFY_COM_IS_DELAY]);
                    }
                    return $this->container[$com_alias_name] = $this->buildInstance($class, $definition, $params, $com_alias_name);
                } else if ($definition instanceof \Closure) {
                    $object = $this->container[$com_alias_name] = call_user_func($definition, $com_alias_name);
                    if (\Swoole\Coroutine::getCid() > 0) {
                        $object->envCoroutineId = \Swoole\Coroutine::getCid();
                    }
                    return $object;
                } else {
                    throw new \Exception(sprintf("component:%s must be set class", $com_alias_name));
                }

            } else {
                return $this->container[$com_alias_name];
            }

        }
        // 配置文件初始化创建公用对象
        $coreComponents = $this->coreComponents();
        $components = array_merge($coreComponents, BaseServer::getAppConf()['components']);
        foreach ($components as $com_name => $component) {
            if ($component instanceof \Closure) {
                // delay create
                continue;
            }

            if (isset($this->container[$com_name]) && is_object($this->container[$com_name])) {
                continue;
            }

            if (isset($component['class']) && !empty($component['class'])) {
                $class = $component['class'];
                unset($component['class']);
                $params = [];
                if (isset($component['constructor'])) {
                    $params = $component['constructor'];
                    unset($component['constructor']);
                }
                $definition = $component;
                $this->container[$com_name] = $this->buildInstance($class, $definition, $params, $com_name);
            } else {
                $this->container[$com_name] = false;
            }
        }
        return $this->container;

    }

    /**
     * getDependencies
     * @param string $class
     * @return array
     * @throws \ReflectionException
     */
    protected function getDependencies(string $class)
    {
        $dependencies = [];
        $reflection = new \ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $param) {
                if ($param->isDefaultValueAvailable()) {
                    $dependencies[] = $param->getDefaultValue();
                } else {
                    $dependencies[] = null;
                }
            }
        }

        return [$reflection, $dependencies];
    }

    /**
     * @param string $class
     * @param $definition
     * @param array $params
     * @param string $com_alias_name
     * @return object
     * @throws \Exception
     */
    protected function buildInstance(string $class, array $definition, array $params, string $com_alias_name)
    {
        /**@var \ReflectionClass $reflection */
        list ($reflection, $dependencies) = $this->getDependencies($class);

        foreach ($params as $index => $param) {
            $dependencies[$index] = $param;
        }

        if (!$reflection->isInstantiable()) {
            throw new \Exception($reflection->name);
        }

        if (empty($definition)) {
            $object = $reflection->newInstanceArgs($dependencies);
            if (\Swoole\Coroutine::getCid() > 0) {
                $object->envCoroutineId = \Swoole\Coroutine::getCid();
            }
            return $object;
        }

        $object = $reflection->newInstanceArgs($dependencies);

        if (\Swoole\Coroutine::getCid() > 0) {
            $object->envCoroutineId = \Swoole\Coroutine::getCid();
        }

        // 回调必须设置在配置的最后
        if (isset($definition[SWOOLEFY_COM_FUNC])) {
            $keys = array_keys($definition);
            if (end($keys) != SWOOLEFY_COM_FUNC) {
                $func = $definition[SWOOLEFY_COM_FUNC];
                unset($definition[SWOOLEFY_COM_FUNC]);
                $definition[SWOOLEFY_COM_FUNC] = $func;
            }
            unset($keys);
        }

        foreach ($definition as $name => $value) {
            if ($name == SWOOLEFY_COM_IS_DELAY) {
                continue;
            }
            if ($name == SWOOLEFY_COM_FUNC) {
                if (is_string($definition[$name]) && method_exists($object, $definition[$name])) {
                    call_user_func_array([$object, $definition[$name]], [$definition]);
                } else if ($definition[$name] instanceof \Closure) {
                    $closure = $definition[$name];
                    $closure->call($object, $definition);
                } else {
                    throw new \Exception(sprintf("%s of component's config item 'func' is not Closure or %s instance is not exists of method", $com_alias_name, $com_alias_name));
                }
                continue;
            } else if (isset($object->$name) && @is_array($object->$name)) {
                $object->$name = array_merge_recursive($object->$name, $value);
                continue;
            }

            $object->$name = $value;
        }

        return $object;
    }

    /**
     * getComponents
     * @param string $com_alias_name
     * @return mixed
     */
    public function getComponents(?string $com_alias_name = null)
    {
        if ($com_alias_name && isset($this->container[$com_alias_name])) {
            return $this->container[$com_alias_name];
        }
        return $this->container;
    }

    /**
     * clearComponent
     * @param string|array $component_alias_name
     * @return boolean
     */
    public function clearComponent(?string $com_alias_name = null)
    {
        if (!is_null($com_alias_name) && is_string($com_alias_name)) {
            $com_alias_name = (array)$com_alias_name;
        } else if (is_array($com_alias_name)) {
            $com_alias_name = array_unique($com_alias_name);
        } else {
            return false;
        }
        foreach ($com_alias_name as $alias_name) {
            if (isset($this->container[$alias_name])) {
                unset($this->container[$alias_name]);
            }
        }
        return true;
    }

    /**
     * coreComponents 定义核心组件
     * @return array
     */
    public function coreComponents()
    {
        return [];
    }

    /**
     * getOpenPoolsOfComponent 获取启用pools的组件名称
     * @return array
     */
    public function getOpenPoolsOfComponent()
    {
        return $this->component_pools ?? [];
    }

    /**
     * @param string $name
     * @return mixed
     * @throws Exception
     */
    final public function get(string $name)
    {
        $app_conf = BaseServer::getAppConf();
        $components = $app_conf['components'];
        $cid = \Swoole\Coroutine::getCid();
        if (isset($this->container[$name])) {
            if (is_object($this->container[$name])) {
                $object = $this->container[$name];
                // 同一个协程中的单例对象,解决在不同协程 $this->get('db') 时组件上下文污染问题
                if (isset($object->envCoroutineId) && $object->envCoroutineId == $cid) {
                    return $object;
                } else {
                    $app = Application::getApp($cid);
                    if (is_object($app)) {
                        $object = $app->getComponents($name);
                        if (is_object($object)) {
                            unset($app);
                            return $object;
                        } else {
                            return $app->creatObject($name, $components[$name]);
                        }
                    }
                }
            }

            $this->clearComponent($name);
            return $this->creatObject($name, $components[$name]);
        }

        if (empty($this->component_pools)) {
            if (isset($app_conf['enable_component_pools']) && is_array($app_conf['enable_component_pools']) && !empty($app_conf['enable_component_pools'])) {
                $enable_component_pools = array_keys($app_conf['enable_component_pools']);
                $this->component_pools = $enable_component_pools;
            }
        }

        if (array_key_exists($name, $components)) {
            // mysql|redis进程池中直接赋值
            if (in_array($name, $this->component_pools) && $cid > 0) {
                /** @var \Swoolefy\Core\Coroutine\PoolsHandler $poolHandler */
                $poolHandler = \Swoolefy\Core\Coroutine\CoroutinePools::getInstance()->getPool($name);
                if (is_object($poolHandler)) {
                    $this->container[$name] = $poolHandler->fetchObj();
                }
                // 若没有设置进程池处理实例，则降级到创建实例模式
                if (isset($this->container[$name]) && is_object($this->container[$name])) {
                    $obj_id = spl_object_id($this->container[$name]);
                    if (!in_array($obj_id, $this->component_pools_obj_ids)) {
                        array_push($this->component_pools_obj_ids, $obj_id);
                    }
                    return $this->container[$name];
                }
            }
            return $this->creatObject($name, $components[$name]);
        }
        return false;

    }

    /**
     * __get
     * @param string $name
     * @return mixed
     * @throws \Exception
     */
    final public function __get($name)
    {
        return $this->get($name);
    }

    /**
     * __unset
     * @param string $name
     */
    public function __unset($name)
    {
        unset($this->$name);
    }

    /**
     * __isset
     * @param string $name
     * @return boolean
     */
    public function __isset($name)
    {
        return isset($this->$name);
    }

    /**
     * __set
     * @param string $name
     * @param mixed $value
     * @return mixed
     */
    public function __set($name, $value)
    {
        if (isset($this->container[$name])) {
            return $this->container[$name];
        } else {
            if (is_array($value) || $value instanceof \Closure) {
                return $this->creatObject($name, $value);
            }
            return false;
        }
    }
} 