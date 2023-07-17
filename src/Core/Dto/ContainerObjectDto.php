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

use Swoolefy\Core\Application;

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
     * @param $name
     * @param $arguments
     * @return mixed
     */
    public function __call($name, $arguments)
    {
        $cid = \Swoole\Coroutine::getCid();
        if ($cid != $this->__coroutineId) {
            return Application::getApp()->get($this->__comAliasName)->$name(...$arguments);
        }
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