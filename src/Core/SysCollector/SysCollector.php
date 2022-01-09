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

namespace Swoolefy\Core\SysCollector;

class SysCollector
{

    use \Swoolefy\Core\SingletonTrait;

    public function test()
    {
        //TODO
        return ['cpu' => '25%', 'memory' => '2345KB'];
    }

}