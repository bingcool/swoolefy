<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
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
     * @var array
     */
    protected $skipEvents = [];

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
    public function skipEvent(string $event) {
        $this->skipEvents[] = $event;
        return $this;
    }

    /**
     * 触发事件
     * @param  string $event 事件名
     * @return bool
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
            if(method_exists(static::class, $call) && !in_array($onEvent, $this->skipEvents)) {
                $result = call_user_func([static::class, $call], $this);
            }
            return false === $result ? false : true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
