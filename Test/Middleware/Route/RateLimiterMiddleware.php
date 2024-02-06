<?php
namespace Test\Middleware\Route;

use Swoolefy\Http\RequestInput;
use Swoolefy\Http\ResponseOutput;
use Swoolefy\Core\RouteMiddleware;
use Test\Factory;

class RateLimiterMiddleware implements RouteMiddleware
{
    public function handle(RequestInput $requestInput, ResponseOutput $responseOutput)
    {
        $uri = $requestInput->getRequestUri();
        $rateLimit = Factory::getRateLimit();
        $rateLimit->setRateKey($uri);
        // 每10s内滑动窗口限制2次请求
        $rateLimit->setLimitParams(2, 10, 60);
        if ($rateLimit->isLimit()) {
            throw new \Exception("请求过快，请稍后重试！");
        }
    }
}