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
     * 当前池租约的私有标识。
     *
     * 该标识只由 PoolsHandler 持有并以对象身份（===）比较，不使用
     * spl_object_id，因而不受对象销毁后 ID 复用影响。
     *
     * @var object|null
     */
    private ?object $__poolLeaseMarker = null;

    /**
     * 当前租约所属协程 ID。
     *
     * @var int
     */
    private int $__poolLeaseOwnerCoroutineId = -1;

    /**
     * 当前容器句柄是否仍持有一个可归还租约。
     *
     * @var bool
     */
    private bool $__poolLeaseActive = false;


    /**
     * @var array
     */
    private $__attributes = ['__coroutineId','__objInitTime','__objExpireTime','__object','__comAliasName'];

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
     * 在对象交给业务前建立一次池租约。
     *
     * 租约元数据随句柄自身生存，不要求对象池反向强引用句柄。方法中只有内存
     * 读写，不包含协程让出点；同一容器不能被重复借出。
     */
    public function beginPoolLease(object $leaseMarker, int $ownerCoroutineId): bool
    {
        if ($this->__poolLeaseActive || !is_object($this->__object)) {
            return false;
        }

        $this->__poolLeaseMarker = $leaseMarker;
        $this->__poolLeaseOwnerCoroutineId = $ownerCoroutineId;
        $this->__poolLeaseActive = true;
        $this->__coroutineId = $ownerCoroutineId;

        return true;
    }

    /**
     * 校验并原子撤销当前池租约。
     *
     * marker 使用对象身份比较以隔离不同 PoolsHandler；owner 阻止其他协程代还。
     * 首次成功后立即把 active 置为 false，因此重复归还只能幂等失败。
     */
    public function claimPoolLease(object $leaseMarker, int $ownerCoroutineId): bool
    {
        if (
            !$this->__poolLeaseActive
            || $this->__poolLeaseMarker !== $leaseMarker
            || $this->__poolLeaseOwnerCoroutineId !== $ownerCoroutineId
            || !is_object($this->__object)
        ) {
            return false;
        }

        $this->__poolLeaseActive = false;
        $this->__poolLeaseMarker = null;
        $this->__poolLeaseOwnerCoroutineId = -1;
        $this->__coroutineId = -1;

        return true;
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