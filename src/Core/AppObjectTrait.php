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

namespace Swoolefy\Core;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;

trait AppObjectTrait
{
    /**
     * _die 异常终端程序执行
     * @param string $html
     * @param string $msg
     * @throws \Exception
     */
    public static function _die($html = '', $msg = '')
    {
        // request end
        Application::getApp()->response->write($html);
        throw new \Exception($msg);
    }
}