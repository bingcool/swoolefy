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

class ContainerObjectDto extends \stdClass
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
     * @var array
     */
    private $__attributes = ['__coroutineId','__objInitTime','__objExpireTime','__object'];

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
     * @param $name
     * @param $arguments
     * @return mixed
     */
    public function __call($name, $arguments)
    {
        return $this->__object->$name(...$arguments);
    }

}