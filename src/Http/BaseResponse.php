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

class BaseResponse {
    /**
     * $code
     */
    private int $code = ResponseCode::CodeOk;

    /**
     * $message
     */
    private string $message = 'success';

    /**
     * $data
     */
    protected $data = [];

    public function setData($data)
    {
        $this->data = $data;
        return $this;
    }

    public function getData()
    {
        return $this->data;
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
