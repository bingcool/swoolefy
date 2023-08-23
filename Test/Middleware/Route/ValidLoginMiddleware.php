<?php
namespace Test\Middleware\Route;

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoolefy\Core\RouteMiddleware;

class ValidLoginMiddleware implements RouteMiddleware
{
    public function handle(Request $request, ?Response $response = null)
    {
        var_dump(__CLASS__);
    }
}