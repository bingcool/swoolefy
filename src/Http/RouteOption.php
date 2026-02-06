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
    const API_LIMIT_NUM_KEY = '__api_limit_num';

    const API_LIMIT_WINDOW_SIZE_TIME_KEY = '__api_limit_window_size_time';

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
     * @var int
     */
    protected $limitNum;

    /**
     * @var int
     */
    protected $windowSizeTime;

    /**
     * 在某个middle之后再运行这个rateLimiterMiddleware, 有可能rateLimiterMiddleware依赖上游的rateLimiterMiddleware的数据
     * 可以是某个group middlewares或者route的before middlewares之后
     * @var string
     */
    protected $runAfterMiddleware;

    /**
     * 路由动态开启DB的debug
     * @param bool $debug
     * @return $this
     */
    public function enableDbDebug(bool $debug)
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
     * @param int $limitNum
     * @param int $windowSizeTime
     * @param string $runAfterMiddleware
     * 在某个middle之后再运行这个rateLimiterMiddleware, 有可能rateLimiterMiddleware依赖上游的rateLimiterMiddleware的数据
     * 如果runAfterMiddleware为空，那么rateLimiterMiddleware将是第一个执行的中间件，执行顺序为$rateLimiterMiddleware->groupMiddleware->routeMiddleware
     * 如果runAfterMiddleware不为空，那么rateLimiterMiddleware将根据这个设置runAfterMiddleware，在它之后执行，这个runAfterMiddleware可以是groupMiddleware或者routeMiddleware其中一个
     * @return $this
     */
    public function withRateLimiterMiddleware(
        string $rateLimiterMiddleware,
        int $limitNum,
        int $windowSizeTime,
        string $runAfterMiddleware = ''
    )
    {
        $this->rateLimiterMiddleware = $rateLimiterMiddleware;
        $this->limitNum = $limitNum;
        $this->windowSizeTime = $windowSizeTime;
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
     * @return int
     */
    public function getLimitNum()
    {
        return $this->limitNum;
    }

    /**
     * @return int
     */
    public function getWindowSizeTime()
    {
        return $this->windowSizeTime;
    }

    /**
     * @return string
     */
    public function getRunAfterMiddleware()
    {
        return $this->runAfterMiddleware;
    }
}