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

use Swoole\FastCGI\Record\Data;
use function Swoole\Coroutine\Http\request;

class BaseResponse {

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
        return (array)$this;
    }
}
