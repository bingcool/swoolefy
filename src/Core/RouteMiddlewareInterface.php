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

use Swoolefy\Http\RequestInput;
use Swoolefy\Http\ResponseOutput;

interface RouteMiddlewareInterface
{
    /**
     * @param RequestInput $requestInput
     * @param ResponseOutput $responseOutput
     * @return mixed
     */
    public function handle(RequestInput $requestInput, ResponseOutput $responseOutput);

}