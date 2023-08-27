<?php
namespace Test\Middleware\Route;

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoolefy\Core\Application;
use Swoolefy\Core\RouteMiddleware;

class ValidLoginMiddleware implements RouteMiddleware
{
    public function handle(Request $request, Response $response)
    {
        $controller = Application::getApp()->getControllerId();
        $actionId = Application::getApp()->getActionId();
        //var_dump($controller, $actionId);
    }
}