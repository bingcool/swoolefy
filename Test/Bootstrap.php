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

use Swoolefy\Core\BootstrapInterface;
use Swoolefy\Http\RequestInput;
use Swoolefy\Http\ResponseOutput;

class Bootstrap implements BootstrapInterface
{
    public static function handle(RequestInput $requestInput, ResponseOutput $responseOutput)
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