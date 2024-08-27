<?php
namespace Test\Middleware\Route;

use Swoolefy\Http\RequestInput;
use Swoolefy\Http\ResponseOutput;
use Swoolefy\Core\RouteMiddleware;
use Swoolefy\Http\RouteOption;
use Test\App;

class RateLimiterMiddleware implements RouteMiddleware
{
    public function handle(RequestInput $requestInput, ResponseOutput $responseOutput)
    {
        $uri = $requestInput->getRequestUri();
        $rateLimit = App::getRateLimit();
        $rateLimit->setRateKey($uri);
        // 每10s内滑动窗口限制2次请求
        $rateLimit->setLimitParams($requestInput->getValue(RouteOption::API_LIMIT_NUM_KEY), $requestInput->getValue(RouteOption::API_LIMIT_WINDOW_SIZE_TIME_KEY));
        if ($rateLimit->isLimit()) {
            throw new \Exception("请求过快，请稍后重试！");
        }else {
            var_dump(__CLASS__);
        }
    }
}