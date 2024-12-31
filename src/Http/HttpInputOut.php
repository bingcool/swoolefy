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

use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;

class HttpInputOut
{
    /**
     * @var SwooleRequest
     */
    protected $swooleRequest;

    /**
     * @var SwooleResponse
     */
    protected $swooleResponse;

    public function __construct(SwooleRequest $swooleRequest, SwooleResponse $swooleResponse)
    {
        $this->swooleRequest  = $swooleRequest;
        $this->swooleResponse = $swooleResponse;
    }

    /**
     * getRequest
     * @return SwooleRequest
     */
    public function getSwooleRequest(): SwooleRequest
    {
        return $this->swooleRequest;
    }

    /**
     * getResponse
     * @return SwooleResponse
     */
    public function getSwooleResponse(): SwooleResponse
    {
        return $this->swooleResponse;
    }
}