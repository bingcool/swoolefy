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

use Swoole\Http\Request;
use Swoole\Http\Response;

interface RouteMiddleware
{
    /**
     * @param Request $request
     * @param Response $response
     * @return mixed
     */
    public function handle(Request $request, ?Response $response = null);

}