<?php

/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

namespace Swoolefy\Http;

class RouteOption extends \stdClass
{
    /**
     * @var bool 路由动态开启DB的debug
     */
    protected $dbDebug = false;

    /**
     * 限流中间件类,需实现\Swoolefy\Core\RouteMiddleware
     *
     * @var string
     */
    protected $rateLimiterMiddleware;

    /**
     * 路由动态开启DB的debug
     * @param bool $debug
     * @return $this
     */
    public function enableDbDebug(bool $debug = false)
    {
        $this->dbDebug = $debug;
        return $this;
    }

    /**
     * @return bool
     */
    public function isEnableDbDebug(): bool
    {
        return $this->dbDebug;
    }

    /**
     * 限流中间件类名，将注册到路由中间件数组表头第一个执行
     *
     * @param string $rateLimiterMiddleware
     * @return $this
     */
    public function withRateLimiterMiddleware(string $rateLimiterMiddleware)
    {
        $this->rateLimiterMiddleware = $rateLimiterMiddleware;
        return $this;
    }

    /**
     * @return string
     */
    public function getRateLimiterMiddleware()
    {
        return $this->rateLimiterMiddleware;
    }
}