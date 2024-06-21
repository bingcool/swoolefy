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

namespace Swoolefy\Core\Controller;

use Swoole\Coroutine;
use Swoolefy\Core\App;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoolefy\Core\Application;

class BController extends \Swoolefy\Core\AppObject
{
    use \Swoolefy\Http\ResponseParseTrait;
    use \Swoolefy\Core\ServiceTrait;

    /**
     * @var SwooleRequest
     */
    public $swooleRequest = null;

    /**
     * @var SwooleResponse
     */
    public $swooleResponse = null;

    /**
     * $appConf
     * @var array
     */
    public $appConf = [];

    /**
     * __construct
     */
    public function __construct()
    {
        /**
         * @var App $app
         */
        $app = Application::getApp();
        $this->swooleRequest  = $app->swooleRequest;
        $this->swooleResponse = $app->swooleResponse;
        $this->appConf  = $app->appConf;
        if (Coroutine::getCid() >= 0) {
            \Swoole\Coroutine::defer(function () {
                $this->defer();
            });
        }
    }

    /**
     * @param string $action
     * @return bool|null
     */
    public function _beforeAction(string $action): ?bool
    {
        return true;
    }


    /**
     * @param string $action
     * @return void
     */
    public function _afterAction(string $action)
    {

    }

    /**
     * defer
     */
    public function defer()
    {
    }
}