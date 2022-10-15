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
use Swoolefy\Core\Application;

class BController extends \Swoolefy\Core\AppObject
{

    use \Swoolefy\Core\AppTrait, \Swoolefy\Core\ServiceTrait;

    /**
     * $request
     * @var \Swoole\Http\Request
     */
    public $request = null;

    /**
     * $response
     * @var \Swoole\Http\Response
     */
    public $response = null;

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
        $app = Application::getApp();
        $this->request  = $app->request;
        $this->response = $app->response;
        $this->appConf  = $app->appConf;
        if (Coroutine::getCid() > 0) {
            \Swoole\Coroutine::defer(function () {
                $this->defer();
            });
        }
    }

    /**
     * defer
     */
    public function defer()
    {
    }
}