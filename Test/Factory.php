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

use Common\Library\RateLimit\DurationLimiter;
use Common\Library\Redis\Redis;
use Common\Library\Db\Mysql;
use Common\Library\Lock\PHPRedisMutex;
use Common\Library\PubSub\RedisPubSub;
use Common\Library\Uuid\UuidManager;
use Swoolefy\Core\Dto\ContainerObjectDto;
use Symfony\Component\Translation\Translator;

class Factory
{
    /**
     * @return Mysql|ContainerObjectDto
     */
    public static function getDb()
    {
        return \Swoolefy\Core\Application::getApp()->get('db');
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
     * @return \Common\Library\Queues\Queue|ContainerObjectDto
     */
    public static function getQueue()
    {
        return \Swoolefy\Core\Application::getApp()->get('queue');
    }

    /**
     * @return \Common\Library\Queues\RedisDelayQueue|ContainerObjectDto
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
}