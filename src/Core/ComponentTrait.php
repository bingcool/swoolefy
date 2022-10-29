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

use Swoolefy\Core\Coroutine\CoroutinePools;
use Swoolefy\Core\Dto\ContainerObjectDto;
use Swoolefy\Exception\SystemException;

trait ComponentTrait
{

    /**
     * containers
     * @var array
     */
    protected $containers = [];

    /**
     * componentPools
     * @var array
     */
    protected $componentPools = [];

    /**
     * componentPoolsObjIds 进程池的组件对象id,区分非来自进程池的组件,因为来自进程的组件不能push到进程池,否则会污染
     * @var array
     */
    protected $componentPoolsObjIds = [];

    /**
     * creatObject
     *
     * @param string $com_alias_name
     * @param \Closure|array $definition
     * @return mixed
     * @throws SystemException
     */
    public function creatObject(string $com_alias_name = null, \Closure|array $definition = [])
    {
        // dynamic create component object
        if ($com_alias_name) {
            if (!isset($this->containers[$com_alias_name]) || !is_object($this->containers[$com_alias_name])) {
                if ($definition instanceof \Closure) {
                    $object = call_user_func($definition, $com_alias_name);
                    return $this->containers[$com_alias_name] = $this->buildContainerObject($object);
                } else if (is_array($definition) && isset($definition['class'])) {
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
                    return $this->containers[$com_alias_name] = $this->buildInstance($class, $definition, $params, $com_alias_name);
                } else {
                    throw new SystemException(sprintf("component:%s must be set class", $com_alias_name));
                }

            } else {
                return $this->containers[$com_alias_name];
            }

        }
        // create component object by config file
        $coreComponents = $this->coreComponents();
        $components = array_merge($coreComponents, BaseServer::getAppConf()['components']);
        foreach ($components as $comName => $component) {
            if ($component instanceof \Closure) {
                // delay create
                continue;
            }

            if (isset($this->containers[$comName]) && is_object($this->containers[$comName])) {
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
                $this->containers[$comName] = $this->buildInstance($class, $definition, $params, $comName);
            } else {
                $this->containers[$comName] = false;
            }
        }
        return $this->containers;

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
            return $object;
        }

        $object = $reflection->newInstanceArgs($dependencies);

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
                    throw new SystemException(sprintf("%s of component's config item 'func' is not Closure or %s instance is not exists of method", $com_alias_name, $com_alias_name));
                }
                continue;
            } else if (isset($object->$name) && @is_array($object->$name)) {
                $object->$name = array_merge_recursive($object->$name, $value);
                continue;
            }

            $object->$name = $value;
        }

        return $this->buildContainerObject($object);
    }

    /**
     * @param object $object
     * @return ContainerObjectDto
     */
    private function buildContainerObject(object $object)
    {
        $containerObjectDto = new ContainerObjectDto();
        $containerObjectDto->__coroutineId = \Swoole\Coroutine::getCid();
        $containerObjectDto->__objInitTime = time();
        $containerObjectDto->__object = $object;
        $containerObjectDto->__objExpireTime = null;
        return $containerObjectDto;
    }

    /**
     * getComponents
     * @param string $com_alias_name
     * @return mixed
     */
    public function getComponents(?string $com_alias_name = null)
    {
        if ($com_alias_name && isset($this->containers[$com_alias_name])) {
            return $this->containers[$com_alias_name];
        }
        return $this->containers;
    }

    /**
     * clearComponent
     * @param string|array $component_alias_name
     * @param bool $isAll
     * @return bool
     */
    public function clearComponent(string|array $com_alias_name, bool $isAll = false)
    {
        if ($isAll) {
            $this->containers = [];
            return true;
        }

        if (is_string($com_alias_name)) {
            $com_alias_name = (array)$com_alias_name;
        } else if (is_array($com_alias_name)) {
            $com_alias_name = array_unique($com_alias_name);
        } else {
            return false;
        }

        foreach ($com_alias_name as $alias_name) {
            if (isset($this->containers[$alias_name])) {
                unset($this->containers[$alias_name]);
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
     * getOpenPoolsOfComponent
     * @return array
     */
    public function getOpenPoolsOfComponent()
    {
        return $this->componentPools ?? [];
    }

    /**
     * @param string $name
     * @return object|bool
     */
    final public function get(string $name)
    {
        $appConf = BaseServer::getAppConf();
        $components = $appConf['components'];
        $cid = \Swoole\Coroutine::getCid();
        if (isset($this->containers[$name])) {
            if (is_object($this->containers[$name])) {
                $containerObject = $this->containers[$name];
                // 同一个协程中的单例对象,解决在不同协程 $this->get('db') 时组件上下文污染问题
                if (isset($containerObject->__coroutineId) && $containerObject->__coroutineId == $cid) {
                    return $containerObject;
                } else {
                    $app = Application::getApp($cid);
                    if (is_object($app)) {
                        $containerObject = $app->getComponents($name);
                        if (is_object($containerObject)) {
                            unset($app);
                            return $containerObject;
                        } else {
                            return $app->creatObject($name, $components[$name]);
                        }
                    }
                }
            }

            $this->clearComponent($name);
            return $this->creatObject($name, $components[$name]);
        }

        if (empty($this->componentPools)) {
            if (isset($appConf['enable_component_pools']) && is_array($appConf['enable_component_pools']) && !empty($appConf['enable_component_pools'])) {
                $enableComponentPools = array_keys($appConf['enable_component_pools']);
                $this->componentPools = $enableComponentPools;
            }
        }

        if (array_key_exists($name, $components)) {
            // mysql|redis进程池中直接赋值
            if (in_array($name, $this->componentPools) && $cid >= 0) {
                /** @var \Swoolefy\Core\Coroutine\PoolsHandler $poolHandler */
                $poolHandler = CoroutinePools::getInstance()->getPool($name);
                if (is_object($poolHandler)) {
                    $this->containers[$name] = $poolHandler->fetchObj();
                }
                // 若没有设置进程池处理实例,则降级到创建实例模式
                if (isset($this->containers[$name]) && is_object($this->containers[$name])) {
                    $objId = spl_object_id($this->containers[$name]);
                    if (!in_array($objId, $this->componentPoolsObjIds)) {
                        array_push($this->componentPoolsObjIds, $objId);
                    }
                    return $this->containers[$name];
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
     */
    final public function __get(string $name)
    {
        return $this->get($name);
    }

    /**
     * __unset
     * @param string $name
     */
    public function __unset(string $name)
    {
        unset($this->$name);
    }

    /**
     * __isset
     * @param string $name
     * @return bool
     */
    public function __isset(string $name)
    {
        return isset($this->$name);
    }

    /**
     * __set
     * @param string $name
     * @param mixed $value
     * @return mixed
     */
    public function __set(string $name, mixed $value)
    {
        if (isset($this->containers[$name])) {
            return $this->containers[$name];
        } else {
            if (is_array($value) || $value instanceof \Closure) {
                return $this->creatObject($name, $value);
            }
            return false;
        }
    }
} 