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
     * @var array
     */
    private $__attributes = ['__coroutineId','__objInitTime','__objExpireTime','__object','__comAliasName','__tagetObjectId'];

    /**
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
     * @return void
     */
    public function __destruct()
    {
        unset($this->__object);
    }

}