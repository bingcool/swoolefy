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

use Swoolefy\Util\Log;
use Common\Library\Db\Mysql;
use Common\Library\Redis\Redis;

$dc = \Swoolefy\Core\SystemEnv::loadDcEnv();

return [
    // redis cache
    'cache' => function() use($dc) {
        $redis = new Redis();
        $redis->connect($dc['redis']['host'], $dc['redis']['port']);
        return $redis;
    }
];