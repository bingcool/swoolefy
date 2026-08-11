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

use Swoolefy\Core\Application;

$dc = \Swoolefy\Core\SystemEnv::loadDcEnv();

return [
    'redis' => function() use($dc) {
        $redis = new \Swoolefy\Library\Redis\Redis();
        $redis->connect($dc['redis']['host'], $dc['redis']['port']);
        return $redis;
    },

    'predis' => function() use($dc) {
        $predis = new \Swoolefy\Library\Redis\predis([
            'scheme' => $dc['predis']['scheme'],
            'host'   => $dc['predis']['host'],
            'port'   => $dc['predis']['port'],
        ]);
        return $predis;
    },

    'cache' => function() use($dc) {
        /**
         * @var \Swoolefy\Library\Redis\RedisConnection $redis
         */
        $redis = Application::getApp()->get('predis')->getObject();
        $cache = new \Swoolefy\Library\Cache\Driver\RedisCache($redis);
        return $cache;

    }
];