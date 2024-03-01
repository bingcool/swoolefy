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
     * 在那个middle之后再运行这个rateLimiterMiddleware, 有可能rateLimiterMiddleware依赖上游的rateLimiterMiddleware的数据
     * 可以是某个group middlewares h或者的route的before middlewares之后
     * @var string
     */
    protected $runAfterMiddleware;

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
     * @param string $runAfterMiddleware //在那个middle之后再运行这个rateLimiterMiddleware, 有可能rateLimiterMiddleware依赖上游的rateLimiterMiddleware的数据
     * @return $this
     */
    public function withRateLimiterMiddleware(string $rateLimiterMiddleware, string $runAfterMiddleware = '')
    {
        $this->rateLimiterMiddleware = $rateLimiterMiddleware;
        $this->runAfterMiddleware = $runAfterMiddleware;
        return $this;
    }

    /**
     * @return string
     */
    public function getRateLimiterMiddleware()
    {
        return $this->rateLimiterMiddleware;
    }

    /**
     * @return string
     */
    public function getRunAfterMiddleware()
    {
        return $this->runAfterMiddleware;
    }
}