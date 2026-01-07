<?php
namespace Test\Middleware\Group;

use Swoolefy\Http\RequestInput;
use Swoolefy\Http\ResponseOutput;
use Swoolefy\Core\RouteMiddlewareInterface;

class GroupTestMiddleware implements RouteMiddlewareInterface
{
    public function handle(RequestInput $requestInput, ResponseOutput $responseOutput)
    {
        //var_dump(__CLASS__);
    }
}