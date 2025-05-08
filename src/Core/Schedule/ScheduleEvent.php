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

use Swoolefy\Worker\Dto\CronForkTaskMetaDto;

class ScheduleEvent extends CronForkTaskMetaDto
{
    /**
     * @var string
     */
    public $command;

    public $cron_meta_origin = 'php';

    /**
     * @var null
     */
    public $timezone = null;

    /**
     * @var string
     */
    const BETWEEN_TIMEAT = 'timeAt';

    /**
     * @var string
     */
    const BETWEEN_DATEAT = 'dateAt';

    /**
     * @param string $command
     * @return $this
     */
    public function command(string $command): self
    {
        $this->command = $command;
        return $this;
    }

    /**
     * @param int|string $cronExpression
     * @return $this
     */
    public function cron($cronExpression): self
    {
        $this->cron_expression = $cronExpression;
        return $this;
    }

    /**
     * 在某些时间段内执行
     *
     * @param string $start
     * @param string $end
     * @return $this
     */
    public function between(string $start, string $end): self
    {
        $this->cron_between[] = [$start, $end];
        return $this;
    }

    /**
     * 跳过某些时段,不能执行
     *
     * @param string $start
     * @param string $end
     * @return $this
     */
    public function skip(string $start, string $end): self
    {
        $this->cron_skip[] = [$start, $end];
        return $this;
    }

    /**
     * @param $start
     * @param $end
     * @return array
     */
    public function parseBetweenTime($start, $end)
    {
        if ($this->isValidTime($start) && $this->isValidTime($end)) {
            $betweenTime = [
                'start' => $start,
                'end'   => $end,
                'type'  => self::BETWEEN_TIMEAT
            ];
        }else if ($this->isValidDateTime($start) && $this->isValidDateTime($end)) {
            if (str_contains($start, ':')) {
                $startDateTime = date('Y-m-d H:i:00', strtotime($start));
            }else {
                $startDateTime = date('Y-m-d 00:00:00', strtotime($start));
            }

            if (str_contains($end, ':')) {
                $endDateTime = date('Y-m-d H:i:59', strtotime($end));
            }else {
                $endDateTime = date('Y-m-d 23:59:59', strtotime($end));
            }

            $betweenTime = [
                'start' => $startDateTime,
                'end'   => $endDateTime,
                'type'  => self::BETWEEN_DATEAT
            ];
        }

        return $betweenTime ?? [];
    }

    /**
     * Schedule the event to run every minute.
     * 每分钟执行任务
     *
     * @return $this
     */
    public function everyMinute()
    {
        return $this->spliceIntoPosition(1, '*');
    }

    /**
     * Schedule the event to run every two minutes.
     *
     * @return $this
     */
    public function everyTwoMinutes()
    {
        return $this->spliceIntoPosition(1, '*/2');
    }

    /**
     * Schedule the event to run every three minutes.
     *
     * @return $this
     */
    public function everyThreeMinutes()
    {
        return $this->spliceIntoPosition(1, '*/3');
    }

    /**
     * Schedule the event to run every four minutes.
     *
     * @return $this
     */
    public function everyFourMinutes()
    {
        return $this->spliceIntoPosition(1, '*/4');
    }

    /**
     * Schedule the event to run every five minutes.
     *
     * @return $this
     */
    public function everyFiveMinutes()
    {
        return $this->spliceIntoPosition(1, '*/5');
    }

    /**
     * Schedule the event to run every ten minutes.
     *
     * @return $this
     */
    public function everyTenMinutes()
    {
        return $this->spliceIntoPosition(1, '*/10');
    }

    /**
     * Schedule the event to run every fifteen minutes.
     *
     * @return $this
     */
    public function everyFifteenMinutes()
    {
        return $this->spliceIntoPosition(1, '*/15');
    }

    /**
     * Schedule the event to run every thirty minutes.
     *
     * @return $this
     */
    public function everyThirtyMinutes()
    {
        return $this->spliceIntoPosition(1, '0,30');
    }

    /**
     * Schedule the event to run hourly.
     * 每小时整点运行任务
     *
     * @return $this
     */
    public function hourly()
    {
        return $this->spliceIntoPosition(1, 0);
    }

    /**
     * Schedule the event to run hourly at a given offset in the hour.
     *
     * @param  array|int  $offset
     * @return $this
     */
    public function hourlyAt($offset)
    {
        $offset = is_array($offset) ? implode(',', $offset) : $offset;

        return $this->spliceIntoPosition(1, $offset);
    }

    /**
     * Schedule the event to run every two hours.
     *
     * @return $this
     */
    public function everyTwoHours()
    {
        return $this->spliceIntoPosition(1, 0)
            ->spliceIntoPosition(2, '*/2');
    }

    /**
     * Schedule the event to run every three hours.
     *
     * @return $this
     */
    public function everyThreeHours()
    {
        return $this->spliceIntoPosition(1, 0)
            ->spliceIntoPosition(2, '*/3');
    }

    /**
     * Schedule the event to run every four hours.
     *
     * @return $this
     */
    public function everyFourHours()
    {
        return $this->spliceIntoPosition(1, 0)
            ->spliceIntoPosition(2, '*/4');
    }

    /**
     * Schedule the event to run every six hours.
     *
     * @return $this
     */
    public function everySixHours()
    {
        return $this->spliceIntoPosition(1, 0)
            ->spliceIntoPosition(2, '*/6');
    }

    /**
     * Schedule the event to run daily.
     *
     * @return $this
     */
    public function daily()
    {
        return $this->spliceIntoPosition(1, 0)
            ->spliceIntoPosition(2, 0);
    }

    /**
     * Schedule the event to run daily at a given time (10:00, 19:30, etc).
     *
     * @param  string  $time
     * @return $this
     */
    public function dailyAt($time)
    {
        $segments = explode(':', $time);

        return $this->spliceIntoPosition(2, (int) $segments[0])
            ->spliceIntoPosition(1, count($segments) === 2 ? (int) $segments[1] : '0');
    }

    /**
     * Schedule the event to run twice daily.
     *
     * @param  int  $first
     * @param  int  $second
     * @return $this
     */
    public function twiceDaily($first = 1, $second = 13)
    {
        return $this->twiceDailyAt($first, $second, 0);
    }

    /**
     * Schedule the event to run twice daily at a given offset.
     *
     * @param  int  $first
     * @param  int  $second
     * @param  int  $offset
     * @return $this
     */
    public function twiceDailyAt($first = 1, $second = 13, $offset = 0)
    {
        $hours = $first.','.$second;

        return $this->spliceIntoPosition(1, $offset)
            ->spliceIntoPosition(2, $hours);
    }

    /**
     * Schedule the event to run only on weekdays.
     *
     * @return $this
     */
    public function weekdays()
    {
        return $this->days(Schedule::MONDAY.'-'.Schedule::FRIDAY);
    }

    /**
     * Schedule the event to run only on weekends.
     *
     * @return $this
     */
    public function weekends()
    {
        return $this->days(Schedule::SATURDAY.','.Schedule::SUNDAY);
    }

    /**
     * Schedule the event to run only on Mondays.
     *
     * @return $this
     */
    public function mondays()
    {
        return $this->days(Schedule::MONDAY);
    }

    /**
     * Schedule the event to run only on Tuesdays.
     *
     * @return $this
     */
    public function tuesdays()
    {
        return $this->days(Schedule::TUESDAY);
    }

    /**
     * Schedule the event to run only on Wednesdays.
     *
     * @return $this
     */
    public function wednesdays()
    {
        return $this->days(Schedule::WEDNESDAY);
    }

    /**
     * Schedule the event to run only on Thursdays.
     *
     * @return $this
     */
    public function thursdays()
    {
        return $this->days(Schedule::THURSDAY);
    }

    /**
     * Schedule the event to run only on Fridays.
     *
     * @return $this
     */
    public function fridays()
    {
        return $this->days(Schedule::FRIDAY);
    }

    /**
     * Schedule the event to run only on Saturdays.
     *
     * @return $this
     */
    public function saturdays()
    {
        return $this->days(Schedule::SATURDAY);
    }

    /**
     * Schedule the event to run only on Sundays.
     *
     * @return $this
     */
    public function sundays()
    {
        return $this->days(Schedule::SUNDAY);
    }

    /**
     * Schedule the event to run weekly.
     *
     * @return $this
     */
    public function weekly()
    {
        return $this->spliceIntoPosition(1, 0)
            ->spliceIntoPosition(2, 0)
            ->spliceIntoPosition(5, 0);
    }

    /**
     * Schedule the event to run weekly on a given day and time.
     *
     * @param  array|mixed  $dayOfWeek
     * @param  string  $time
     * @return $this
     */
    public function weeklyOn($dayOfWeek, $time = '0:0')
    {
        $this->dailyAt($time);

        return $this->days($dayOfWeek);
    }

    /**
     * Schedule the event to run monthly.
     *
     * @return $this
     */
    public function monthly()
    {
        return $this->spliceIntoPosition(1, 0)
            ->spliceIntoPosition(2, 0)
            ->spliceIntoPosition(3, 1);
    }

    /**
     * Schedule the event to run monthly on a given day and time.
     *
     * @param  int  $dayOfMonth
     * @param  string  $time
     * @return $this
     */
    public function monthlyOn($dayOfMonth = 1, $time = '0:0')
    {
        $this->dailyAt($time);

        return $this->spliceIntoPosition(3, $dayOfMonth);
    }

    /**
     * Schedule the event to run twice monthly at a given time.
     *
     * @param  int  $first
     * @param  int  $second
     * @param  string  $time
     * @return $this
     */
    public function twiceMonthly($first = 1, $second = 16, $time = '0:0')
    {
        $daysOfMonth = $first.','.$second;

        $this->dailyAt($time);

        return $this->spliceIntoPosition(3, $daysOfMonth);
    }

    /**
     * Schedule the event to run on the last day of the month.
     *
     * @param  string  $time
     * @return $this
     */
    public function lastDayOfMonth($time = '0:0')
    {
        $this->dailyAt($time);
        // 获取当月最后一天的Cron表达式
        $cron = new \Cron\CronExpression('0 0 L * *');
        // 获取当前时间
        $now = new \DateTime('now');
        // 获取当前月份的最后一天
        $lastDayOfMonth = $cron->getNextRunDate($now);
        $lastDay = (int)$lastDayOfMonth->format('d');

        return $this->spliceIntoPosition(3,  $lastDay);
    }

    /**
     * Schedule the event to run quarterly.
     *
     * @return $this
     */
    public function quarterly()
    {
        return $this->spliceIntoPosition(1, 0)
            ->spliceIntoPosition(2, 0)
            ->spliceIntoPosition(3, 1)
            ->spliceIntoPosition(4, '1-12/3');
    }

    /**
     * Schedule the event to run yearly.
     *
     * @return $this
     */
    public function yearly()
    {
        return $this->spliceIntoPosition(1, 0)
            ->spliceIntoPosition(2, 0)
            ->spliceIntoPosition(3, 1)
            ->spliceIntoPosition(4, 1);
    }

    /**
     * Schedule the event to run yearly on a given month, day, and time.
     *
     * @param  int  $month
     * @param  int|string  $dayOfMonth
     * @param  string  $time
     * @return $this
     */
    public function yearlyOn($month = 1, $dayOfMonth = 1, $time = '0:0')
    {
        $this->dailyAt($time);

        return $this->spliceIntoPosition(3, $dayOfMonth)
            ->spliceIntoPosition(4, $month);
    }

    /**
     * Set the days of the week the command should run on.
     *
     * @param  array|mixed  $days
     * @return $this
     */
    public function days($days)
    {
        $days = is_array($days) ? $days : func_get_args();

        return $this->spliceIntoPosition(5, implode(',', $days));
    }

    /**
     * Set the timezone the date should be evaluated on.
     *
     * @param  string  $timezone
     * @return $this
     */
    public function timezone($timezone)
    {
        $this->timezone = $timezone;
        date_default_timezone_set($timezone);
        return $this;
    }

    /**
     * @param $forkType
     * @return $this
     */
    public function forkType($forkType): self
    {
        $this->fork_type = $forkType;
        return $this;
    }

    /**
     * @param string $name
     * @param $value
     * @return $this
     */
    public function addArgs(string $name, $value):self
    {
        $this->argv[$name] = $value;
        return $this;
    }

    /**
     * @param string $description
     * @return $this
     */
    public function description(string $description = ''): self
    {
        $this->description = $description;
        return $this;
    }

    /**
     * @return $this
     */
    public function withBlockLapping(): self
    {
        $this->with_block_lapping = true;
        return $this;
    }

    /**
     * fork process success
     *
     * @param \Closure $callback
     * @return $this
     */
    public function ForkSuccessCallback(\Closure $callback): self
    {
        $this->fork_success_callback = $callback;
        return $this;
    }

    /**
     * fork process fail
     *
     * @param \Closure $callback
     * @return $this
     */
    public function ForkFailCallback(\Closure $callback): self
    {
        $this->fork_fail_callback = $callback;
        return $this;
    }

    /**
     * Splice the given value into the given position of the expression.
     *
     * @param  int  $position
     * @param  string  $value
     * @return $this
     */
    protected function spliceIntoPosition($position, $value)
    {
        $segments = explode(' ', $this->cron_expression);

        $segments[$position - 1] = $value;

        return $this->cron(implode(' ', $segments));
    }

    /**
     * @param $time
     * @return bool
     */
    protected  function isValidTime($time)
    {
        $pattern = '/^([01]?[0-9]|2[0-3]):([0-5]?[0-9])$/';
        return preg_match($pattern, $time) === 1;
    }

    /**
     * @param $time
     * @return bool
     */
    protected  function isValidDateTime($dateTime)
    {
        $timestamp = strtotime($dateTime);
        if (is_numeric($timestamp) && $timestamp > 0) {
            return true;
        }
        return false;
    }

    /**
     * @return string
     */
    public function getCronExpression()
    {
        return $this->cron_expression;
    }
}