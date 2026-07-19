<?php
namespace Test\Middleware\Route;

use Swoolefy\Http\Middleware\ApiRateLimiterMiddleware;

/**
 * 可继承ApiRateLimiterMiddleware 重新
 * @see ApiRateLimiterMiddleware
 */
class RateLimiterMiddleware extends ApiRateLimiterMiddleware
{
}
