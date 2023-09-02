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
use Common\Library\Cache\Redis;

$dc = \Swoolefy\Core\SystemEnv::loadDcEnv();

return [
    // redis cache
    // mysql db
    'db' => function() use($dc) {
        $db = new Mysql($dc['mysql_db']);
        return $db;
    }
];