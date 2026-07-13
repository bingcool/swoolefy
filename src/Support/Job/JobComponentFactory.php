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

namespace Swoolefy\Support\Job;

/**
 * Job 模块薄工厂：Runner / Publisher / 死信等装配入口。
 */
final class JobComponentFactory
{
    public static function config(): JobConfig
    {
        return JobConfig::load();
    }

    public static function runner(?JobConfig $config = null): JobRunner
    {
        $config ??= self::config();

        // timeoutSeconds 已预留；真正 TimeoutGuard 接入见 Phase 3
        return new JobRunner(
            $config->retryPolicy(),
            $config->handlerTimeoutSeconds(),
        );
    }

    /**
     * @param callable(array<string, mixed>): void $publish
     */
    public static function publisher(callable $publish, ?JobConfig $config = null): JobPublisher
    {
        $config ??= self::config();

        return new JobPublisher($publish, $config->retryPolicy());
    }

    public static function redisDeadLetter(object $redis, ?JobConfig $config = null): RedisDeadLetter
    {
        return RedisDeadLetter::fromConfig($redis, $config);
    }

    public static function registry(JobHandlerInterface ...$handlers): JobHandlerRegistry
    {
        return (new JobHandlerRegistry())->registerMany($handlers);
    }
}
