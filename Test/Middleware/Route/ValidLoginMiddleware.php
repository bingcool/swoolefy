<?php
namespace Test\Middleware\Route;

use Swoolefy\Http\RequestInput;
use Swoolefy\Http\ResponseOutput;
use Swoolefy\Core\RouteMiddlewareInterface;

class ValidLoginMiddleware implements RouteMiddlewareInterface
{
    public function handle(RequestInput $requestInput, ResponseOutput $responseOutput): bool
    {
        var_dump(__CLASS__);

        return true;
    }
}
