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

/** Job ID 生成器。 */
final class JobId
{
    public static function generate(string $prefix = 'job'): string
    {
        return $prefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(6));
    }
}
