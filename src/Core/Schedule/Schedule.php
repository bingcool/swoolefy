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

namespace Swoolefy\Core\Schedule;

use Swoolefy\Core\SingletonTrait;

class Schedule
{
    use SingletonTrait;

    const SUNDAY = 0;
    const MONDAY = 1;
    const TUESDAY = 2;
    const WEDNESDAY = 3;
    const THURSDAY = 4;
    const FRIDAY = 5;
    const SATURDAY = 6;

    /**
     * @var array
     */
    public $scheduleEvents = [];

    /**
     * @param string $command
     * @return ScheduleEvent
     */
    public function command(string $command): ScheduleEvent
    {
        $this->scheduleEvents[] = $event = new ScheduleEvent();
        $event->command = $command;
        return $event;
    }

    /**
     * @param ScheduleEvent $event
     * @return $this
     */
    public function addScheduleEvent(ScheduleEvent $event)
    {
        $this->scheduleEvents[] = $event->toArray();
        return $this;
    }

    /**
     * @return array
     */
    public function toArray()
    {
        $scheduleMeta = [];
        foreach ($this->scheduleEvents as $event) {
            if ($event instanceof ScheduleEvent) {
                /**
                 * @var ScheduleEvent $event
                 */
                $scheduleMeta[] = $event->toArray();
            }else if (is_array($event)) {
                $scheduleMeta[] = $event;
            }
        }

        return $scheduleMeta;
    }
}