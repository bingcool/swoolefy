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

namespace Swoolefy\Library\Db\Concern;

/**
 * 模型事件处理
 */
trait ModelEvent
{
    /**
     * 是否需要事件响应
     * @var bool
     */
    protected $withEvent = true;

    /**
     * 某些场景下需要设置忽略执行的事件
     * @var array
     */
    protected $skipEvents = [];

    /**
     * 在某些情况下，并不需要执行Model原生定义的事件处理函数，那么提供自定义处理或者设置忽略处理
     * @var array 动态定制事件事件处理回调函数，不使用固定的。如果设置自定义事件，则优先执行自定义事件
     */
    protected $customEventHandlers = [];

    /**
     * 当前操作的事件响应
     * @param  bool $event  是否需要事件响应
     * @return $this
     */
    public function withEvent(bool $event)
    {
        $this->withEvent = $event;
        return $this;
    }

    /**
     * @param string $event
     * @return $this
     */
    public function skipEvent(string $event)
    {
        $this->skipEvents[] = $event;
        return $this;
    }

    /**
     * 触发事件
     * @param  string $event 事件名
     * @return bool
     * @throws Exception
     */
    protected function trigger(string $event): bool
    {
        if (!$this->withEvent) {
            return true;
        }
        $onEvent = self::studly($event);
        $call = 'on' . $onEvent;

        try {
            $result = null;
            /**@var \Closure $callFunction*/
            if(isset($this->customEventHandlers[$onEvent]) && $this->customEventHandlers[$onEvent] instanceof \Closure) {
                $callFunction = $this->customEventHandlers[$onEvent];
                $result = $callFunction->call($this);
            }else {
                if(method_exists(static::class, $call) && !in_array($onEvent, $this->skipEvents)) {
                    $result = $this->{$call}();
                }
            }

            return false === $result ? false : true;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * @param $event
     * @param \Closure $func
     * @throws Exception
     */
    public function setEventHandle($event, \Closure $func)
    {
        if(!in_array($event, [
            static::BEFORE_INSERT,
            static::AFTER_INSERT,
            static::BEFORE_UPDATE,
            static::AFTER_UPDATE,
            static::BEFORE_DELETE,
            static::AFTER_DELETE
        ])) {
            throw new \Exception("addEventHandle first argument of eventName type error");
        }

        $this->customEventHandlers[$event] = $func;
    }

    /**
     * @param string $event
     * @return array|null
     */
    public function getEventHandle($event = '')
    {
        if($event) {
            $handle = $this->customEventHandlers[$event] ?? null;
        }else {
            $handle = $this->customEventHandlers;
        }
        return $handle;
    }
}
