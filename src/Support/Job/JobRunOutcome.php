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

declare(strict_types=1);

namespace Swoolefy\Support\Job;

/** {@see JobRunner::run()} 的执行结果（测试与指标用）。 */
enum JobRunOutcome: string
{
    case SUCCESS = 'success';
    case REQUEUED = 'requeued';
    case DEAD = 'dead';
    case DISCARDED = 'discarded';
}
