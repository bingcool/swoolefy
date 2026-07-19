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

namespace Test;

use Swoolefy\Library\Db\Pgsql;
use Swoolefy\Library\RateLimit\DurationLimiter;
use Swoolefy\Library\Redis\Redis;
use Swoolefy\Library\Db\Mysql;
use Swoolefy\Library\Lock\PHPRedisMutex;
use Swoolefy\Library\PubSub\RedisPubSub;
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
     * @return \Swoolefy\Library\Queues\Queue|ContainerObjectDto
     */
    public static function getQueue()
    {
        return \Swoolefy\Core\Application::getApp()->get('queue');
    }

    /**
     * @return \Swoolefy\Library\Queues\RedisDelayQueue|ContainerObjectDto
     */
    public static function getDelayQueue()
    {
        return \Swoolefy\Core\Application::getApp()->get('delayQueue');
    }

    /**
     * @return RedisPubSub|ContainerObjectDto
     */
    public static function getRedisSubscribe()
    {
        return \Swoolefy\Core\Application::getApp()->get('redis-subscribe');
    }

    public static function getAmqpConnection()
    {
        return \Swoolefy\Core\Application::getApp()->get('amqpConnection')->getObject();
    }

    /**
     * @return DurationLimiter|ContainerObjectDto
     */
    public static function getRateLimit()
    {
        return \Swoolefy\Core\Application::getApp()->get('rateLimit');
    }

    /**
     * @return PHPRedisMutex|ContainerObjectDto
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
}