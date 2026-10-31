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

declare(strict_types=1);

use Swoolefy\Core\Application;

$dc = \Swoolefy\Core\SystemEnv::loadDcEnv();

return [
    'redis' => function() use($dc) {
        $redis = new \Swoolefy\Library\Redis\Redis();
        $redis->connect($dc['redis']['host'], $dc['redis']['port']);
        // 带密码的 Redis（本地 docker-compose / 测试环境）需要显式 auth，否则 PING 返回 NOAUTH
        $password = (string) env('REDIS_PASSWORD', '');
        if ('' !== $password) {
            $redis->auth($password);
        }
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