<?php
/**
+----------------------------------------------------------------------
| swoolefy framework bases on swoole extension development, we can use it easily!
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| @see https://github.com/bingcool/swoolefy
+----------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Test;

use Swoolefy\Library\Db\Pgsql;
use Swoolefy\Library\RateLimit\DurationLimiter;
use Swoolefy\Library\Redis\Redis;
use Swoolefy\Library\Db\Mysql;
use Swoolefy\Library\Lock\RedisMutex;
use Swoolefy\Library\Mongodb\MongodbModel;
use Swoolefy\Library\Uuid\UuidManager;
use Swoolefy\Core\Dto\ContainerObjectDto;
use Swoolefy\Support\Auth\JwtAuthGuard;
use Symfony\Component\Translation\Translator;

class App
{
    /**
     * @return Mysql|ContainerObjectDto
     */
    public static function getDb()
    {
        return \Swoolefy\Core\Application::getApp()->get('db');
    }

    /**
     * @return Pgsql|ContainerObjectDto
     */
    public static function getPgSql()
    {
        return \Swoolefy\Core\Application::getApp()->get('pg');
    }

    /**
     * @return Mysql|ContainerObjectDto
     */
    public static function makeNewDb()
    {
        return \Swoolefy\Core\Application::getApp()->makeNewObject('db');
    }

    /**
     * @return Redis|ContainerObjectDto
     */
    public static function getRedis()
    {
        return \Swoolefy\Core\Application::getApp()->get('redis');
    }

    /**
     * @return Redis|ContainerObjectDto
     */
    public static function getPredis()
    {
        return \Swoolefy\Core\Application::getApp()->get('predis');
    }

    /**
     * @return UuidManager|ContainerObjectDto
     */
    public static function getUUid()
    {
        return \Swoolefy\Core\Application::getApp()->get('uuid');
    }

    /**
     * queue 组件里的 Redis 连接。业务用这个连接和自己的键创建 Queue。
     *
     * @return Redis|ContainerObjectDto
     */
    public static function getQueue()
    {
        return \Swoolefy\Core\Application::getApp()->get('queue');
    }

    /**
     * delayQueue 组件里的 phpredis 连接。业务用这个连接和自己的键创建 RedisDelayQueue。
     *
     * @return Redis|ContainerObjectDto
     */
    public static function getDelayQueue()
    {
        return \Swoolefy\Core\Application::getApp()->get('delayQueue');
    }

    /**
     * @return DurationLimiter|ContainerObjectDto
     */
    public static function getRateLimit()
    {
        return \Swoolefy\Core\Application::getApp()->get('rateLimit');
    }

    /**
     * 组件容器按协程隔离，同一协程复用同一个 mutex；mutex 持有 token/租期状态，
     * 只能顺序使用，禁止传给其他协程。
     *
     * @return RedisMutex|ContainerObjectDto
     */
    public static function getRedisLock()
    {
        return \Swoolefy\Core\Application::getApp()->get('redis-order-lock');
    }

    /**
     * @return Translator|ContainerObjectDto
     */
    public static function getTranslator()
    {
        return \Swoolefy\Core\Application::getApp()->get('translator');
    }

    /**
     * @return JwtAuthGuard|ContainerObjectDto
     */
    public static function getAuthGuard()
    {
        return \Swoolefy\Core\Application::getApp()->get('auth.guard');
    }

    /**
     * mongodb 组件按协程缓存；模型持有 Client 与 collection 缓存，不能跨协程共享。
     *
     * @return MongodbModel|ContainerObjectDto
     */
    public static function getMongodb()
    {
        return \Swoolefy\Core\Application::getApp()->get('mongodb');
    }
}