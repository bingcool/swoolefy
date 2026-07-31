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

use Swoole\Http\Status as HttpStatus;

/**
 * 请求体 JSON 非法：对外固定 400 + error_code=invalid_json，不回传完整 body。
 */
class InvalidJsonException extends DispatchException
{
    public const ERROR_CODE = 'invalid_json';

    public static function fromJsonException(\JsonException $previous, string $requestId = ''): self
    {
        // 仅暴露解析原因，禁止把 raw body 写入 message / context
        $exception = new self('Invalid JSON body', HttpStatus::BAD_REQUEST, $previous);
        $exception->setContextData([
            'error_code' => self::ERROR_CODE,
            'request_id' => $requestId,
            'reason' => $previous->getMessage(),
        ]);

        return $exception;
    }
}
