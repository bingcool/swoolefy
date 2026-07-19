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

use Swoole\Http\Status;

/**
 * HTTP 限流触发异常。
 *
 * 继承 {@see SystemException}，以便全局异常处理按 `$throwable->getCode()` 写回 HTTP 状态
 *（默认 429 Too Many Requests），而不是被统一包装成 500。
 *
 * @see AbstractRateLimiterMiddleware::handle()
 */
final class RateLimitExceededException extends SystemException
{
    /**
     * @param string          $message  客户端可见文案（可被 Config `message` 覆盖）
     * @param int             $code     HTTP 状态码，默认 {@see Status::TOO_MANY_REQUESTS} = 429
     * @param \Throwable|null $previous 底层异常（一般限流路径为 null）
     */
    public function __construct(
        string $message = 'Too Many Requests',
        int $code = Status::TOO_MANY_REQUESTS,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
