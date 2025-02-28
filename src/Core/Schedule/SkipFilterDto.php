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

class SkipFilterDto
{

    /**
     * @var string
     */
    const BETWEEN_TIMEAT = 'timeAt';

    /**
     * @var string
     */
    const BETWEEN_DATEAT = 'dateAt';

    /**
     * @param array $between
     * @return bool
     */
    public function filter(array $between)
    {
        if (!empty($between)) {
            $start = $between['start'];
            $end   = $between['end'];
            $type  = $between['type'];
            $time = time();
            switch ($type) {
                case self::BETWEEN_TIMEAT:
                    $date      = date('Y-m-d');
                    $startTime = strtotime($date.' '.$start);
                    $endTime   = strtotime($date.' '.$end);
                    break;
                case self::BETWEEN_DATEAT:
                    $startTime = strtotime($start);
                    $endTime   = strtotime($end);
                    break;
                default:
                    return false;
            }

            // diff between
            if ($startTime <= $time && $time <= $endTime) {
                return false;
            }
            return true;
        }
        return true;
    }
}