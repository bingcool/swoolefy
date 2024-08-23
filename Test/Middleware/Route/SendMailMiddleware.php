<?php
namespace Test\Middleware\Route;

use Swoolefy\Http\RequestInput;
use Swoolefy\Http\ResponseOutput;
use Swoolefy\Core\RouteMiddleware;

class SendMailMiddleware implements RouteMiddleware
{
    public function handle(RequestInput $requestInput, ResponseOutput $responseOutput)
    {
        var_dump("controller业务已处理完毕并返回结果，现在发送邮件处理");
    }
}