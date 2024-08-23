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

class Schedule
{
    use SingletonTrait;

    public $scheduleEvents = [];
    public function command(string $command): ScheduleEvent
    {
        $this->scheduleEvents[] = $event = new ScheduleEvent();
        $event->command = $command;
        return $event;
    }

    public function addScheduleEvent(ScheduleEvent $event)
    {
        $this->scheduleEvents[] = $event;
        return $this;
    }

    /**
     * @return array
     */
    public function toArray()
    {
        $scheduleMeta = [];
        foreach ($this->scheduleEvents as $event) {
            /**
             * @var ScheduleEvent $event
             */
            $scheduleMeta[] = $event->toArray();
        }

        return $scheduleMeta;
    }
}