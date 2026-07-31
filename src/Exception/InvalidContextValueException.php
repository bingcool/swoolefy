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

/**
 * Context 传播值含对象/资源/Closure 等不可序列化类型时抛出（仅暴露键名与类型）。
 */
class InvalidContextValueException extends SystemException
{

}
