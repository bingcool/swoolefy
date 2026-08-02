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

namespace Swoolefy\Exception;

use RuntimeException;

/**
 * HTTP 路由中间件配置非法（类不存在、未实现约定接口、不可调用）—— fail closed，应在启动期抛出。
 */
final class InvalidMiddlewareException extends RuntimeException
{
}
