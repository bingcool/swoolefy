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
     * define response formatter
     *
     * @param int $code
     * @param string $msg
     * @param string $data
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
}