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
     * 路由中间件入口。
     *
     * @return bool
     * true=继续后续中间件，程序将继续往下执行；
     * false=中止程序往下执行，不会再执行其他中间件（false一般由框架内置的中间件才返回做特殊逻辑处理，eg:CORS 预检拒绝等）。在业务层定义的中间件直接抛异常，不要返回false
     */
    public function handle(RequestInput $requestInput, ResponseOutput $responseOutput): bool;
}
