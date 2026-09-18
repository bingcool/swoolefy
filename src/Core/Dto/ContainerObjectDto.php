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

namespace Swoolefy\Core\Dto;

use Swoolefy\Core\Coroutine\PoolFallbackLease;

/**
 * 组件容器包装对象：把真实连接藏在 __object 里，框架元数据走私有属性。
 *
 * 池化对象与 fallback 对象共用本 DTO。区分方式：
 * - 池化：__fallbackLease=null，且 spl_object_id 记入 ComponentTrait::$componentPoolsObjIds
 * - 降级：挂 {@see PoolFallbackLease}，请求结束关连接，绝不 push 回 channel
 */
class ContainerObjectDto extends AbstractDto
{
    /**
     * 当前租约所属的协程 ID。
     *
     * 对象在空闲 channel 中、已被接受归还但尚未完成结算时均为 -1；只有 fetchObj()
     * 成功借出后才写入实际 cid。PoolsHandler 据此拒绝重复归还、跨协程归还及陈旧
     * handle，防止同一对象被并发放回 channel 两次。
     *
     * @var int
     */
    private $__coroutineId;

    /**
     * @var int
     */
    private $__objInitTime;

    /**
     * @var int
     */
    private $__objExpireTime;

    /**
     * @var mixed
     */
    private $__object;

    /**
     * @var string
     */
    private $__comAliasName;

    /**
     * spl_object_id($__object)
     * @var int
     *
     */
    private $__tagetObjectId;

    /**
     * 池外降级连接的配额租约；池化对象保持 null。
     *
     * 额度与对象生命周期绑定：clearComponent / 析构都会 release，lease 内部幂等。
     */
    private mixed $__fallbackLease = null;

    /**
     * @var array
     */
    private $__attributes = ['__coroutineId','__objInitTime','__objExpireTime','__object','__comAliasName','__tagetObjectId','__fallbackLease'];

    /**
     * 框架元数据走私有属性；其余赋值转发到真实连接对象。
     *
     * @param $name
     * @param $value
     */
    public function __set($name, $value)
    {
        if(in_array($name, $this->__attributes)) {
            $this->$name = $value;
        }else {
            $this->__object->$name = $value;
        }
    }

    /**
     * 读取框架元数据或转发到真实连接。
     *
     * @param $name
     * @return mixed
     */
    public function __get($name)
    {
        if(in_array($name, $this->__attributes)) {
           return $this->$name;
        }else {
            return $this->__object->$name;
        }
    }

    /**
     * 必须与 {@see __get()} 对齐。
     *
     * 私有属性在类外 `isset($dto->__coroutineId)` 若不走 __isset，PHP 恒为 false。
     * ComponentTrait::get() 会因此误判成跨协程，再 creatObject 多占连接/额度。
     */
    public function __isset($name)
    {
        if (in_array($name, $this->__attributes, true)) {
            return isset($this->$name);
        }

        return isset($this->__object->$name);
    }

    /**
     * @return mixed
     */
    public function getObject()
    {
        return $this->__object;
    }

    /**
     * @return int
     */
    public function getTargetObjectId()
    {
        if ($this->__tagetObjectId > 0) {
            return $this->__tagetObjectId;
        } else {
            $this->__tagetObjectId = spl_object_id($this->__object);
        }
        return $this->__tagetObjectId;
    }

    /**
     * @param $name
     * @param $arguments
     * @return mixed
     */
    public function __call($name, $arguments)
    {
        return $this->__object->$name(...$arguments);
    }

    /**
     * 对象销毁时归还 fallback 额度（若有）。池化对象 lease 为 null，无副作用。
     */
    public function __destruct()
    {
        if ($this->__fallbackLease instanceof PoolFallbackLease) {
            $this->__fallbackLease->release();
        }
        unset($this->__fallbackLease, $this->__object);
    }

    /**
     * clone 不得带走租约：否则两个 DTO 析构会对同一 inflight 减两次。
     * 克隆体若仍当降级连接用，必须重新 reserve 并挂新 lease。
     */
    public function __clone()
    {
        $this->__fallbackLease = null;
    }
}