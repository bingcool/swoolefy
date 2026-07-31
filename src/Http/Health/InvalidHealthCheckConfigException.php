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

namespace Swoolefy\Http\Health;

use RuntimeException;

/**
 * 健康检查配置非法（未知 type、无效 class 等）—— fail closed，应在启动期抛出。
 */
final class InvalidHealthCheckConfigException extends RuntimeException
{
}
