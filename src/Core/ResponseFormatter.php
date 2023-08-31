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
    public static function formatterData(int $code = 0, string $msg = '', $data = [])
    {
        $response = new BaseResponseDto();
        $response->code = $code;
        $response->msg  = $msg;
        $response->data = $data;
        return $response->toArray();
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
        mixed $data = []
    ): array
    {
        $responseFormatter = (!isset(Swfy::getConf()['response_formatter']) || empty(Swfy::getConf()['response_formatter'])) ? ResponseFormatter::class : Swfy::getConf()['response_formatter'];
        return $responseFormatter::formatterData($code, $msg, $data);
    }
}