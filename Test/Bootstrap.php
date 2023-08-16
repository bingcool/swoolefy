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

namespace Test;

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoolefy\Core\BootstrapInterface;

class Bootstrap implements BootstrapInterface
{
    public static function handle(Request $request, Response $response)
    {
//        $list = Db::table('tbl_users')->where('user_id','=', 203)->select();
//        var_dump($list);

//        SystemException::throw(
//            "数据缺失",
//            ['uid' => 100]
//        );

        //return Application::getApp()->beforeEnd(500);
    }
}