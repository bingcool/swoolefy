<?php
namespace Test\Middleware\Route;

use Swoolefy\Http\RequestInput;
use Swoolefy\Http\ResponseOutput;
use Swoolefy\Core\RouteMiddleware;

class ValidLoginMiddleware implements RouteMiddleware
{
    public function handle(RequestInput $requestInput, ResponseOutput $responseOutput)
    {
        var_dump(__CLASS__);
    }
}