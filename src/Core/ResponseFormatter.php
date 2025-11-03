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

namespace Swoolefy\Core;

use Swoolefy\Core\Coroutine\Context as SwooleContext;
use Swoolefy\Core\Dto\BaseResponseDto;

class ResponseFormatter
{
    /**
     * define response formatter handle
     *
     * @param int $code
     * @param string $msg
     * @param mixed $data
     * @return array
     */
    public static function formatDataArray(int $code = 0, string $msg = '', $data = [])
    {
        $responseDto = static::formatDataDto($code, $msg, $data);
        return $responseDto->toArray();
    }

    /**
     * define response formatter handle
     *
     * @param int $code
     * @param string $msg
     * @param mixed $data
     * @return BaseResponseDto
     */
    public static function formatDataDto(int $code = 0, string $msg = '', $data = []): BaseResponseDto
    {
        $responseDto = new BaseResponseDto();
        $responseDto->code = $code;
        $responseDto->msg  = $msg;
        if (SwooleContext::has('x-trace-id')) {
            $responseDto->trace_id = SwooleContext::get('x-trace-id');
        }
        $responseDto->data = $data;
        return $responseDto;
    }

    /**
     * @param int $code
     * @param string $msg
     * @param mixed $data
     * @return array
     */
    final public static function buildResponseData(
        int $code = 0,
        string $msg = '',
        $data = []
    ): array
    {
        $responseFormatter = (!isset(Swfy::getConf()['response_formatter']) || empty(Swfy::getConf()['response_formatter'])) ? ResponseFormatter::class : Swfy::getConf()['response_formatter'];
        return $responseFormatter::formatDataArray($code, $msg, $data);
    }
}