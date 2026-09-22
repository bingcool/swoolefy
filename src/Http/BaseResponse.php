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

namespace Swoolefy\Http;

use Swoolefy\Core\Dto\ArrayDto;

class BaseResponse extends ArrayDto
{
    /**
     * $code
     */
    private int $code = ResponseCode::CodeOk;

    /**
     * $msg
     */
    private string $msg = 'success';

    public function setData($data)
    {
        $this->data = $data;
        return $this;
    }

    public function getData()
    {
        return $this->data;
    }

    public static function builder(): static
    {
        return new static();
    }
}
