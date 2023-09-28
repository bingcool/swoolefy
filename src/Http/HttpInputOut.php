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

use Swoole\Http\Request;
use Swoole\Http\Response;

class HttpInputOut
{
    /**
     * @var Request
     */
    protected $request;

    /**
     * @var Response
     */
    protected $response;

    public function __construct(Request $request, Response $response)
    {
        $this->request = $request;
        $this->response = $response;
    }

    /**
     * getRequest
     * @return Request
     */
    public function getRequest(): Request
    {
        return $this->request;
    }

    /**
     * getResponse
     * @return Response
     */
    public function getResponse(): Response
    {
        return $this->response;
    }
}