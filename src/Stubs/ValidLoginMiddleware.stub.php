<?php

namespace MY_APP_NAME\Middleware\Route;

use Swoolefy\Core\RouteMiddlewareInterface;
use Swoolefy\Http\RequestInput;
use Swoolefy\Http\ResponseOutput;

/**
 * ValidLoginMiddleware
 */
class ValidLoginMiddleware implements RouteMiddlewareInterface
{
    public function handle(RequestInput $requestInput, ResponseOutput $responseOutput)
    {
        var_dump('this is login middleware');
    }
}
