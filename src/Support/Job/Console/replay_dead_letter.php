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

/**
 * 将 Redis 死信 List 中的任务重放到发布目标。
 *
 * 用法：
 *   php src/Support/Job/Console/replay_dead_letter.php --queue=default --limit=10
 *
 * 需要 APP_PATH + redis 组件（建议在应用 bootstrap 后运行）。
 * 单测请优先用 RedisDeadLetter::replay() + 内存假 Redis。
 *
 * 环境变量：
 *   JOB_DLQ_REDIS_PREFIX  key 前缀（默认 job:dead:）
 *   JOB_REPLAY_MODE       queue|stdout（无应用上下文时默认 stdout；有 App 时默认 queue）
 */

use Swoolefy\Support\Job\JobConfig;
use Swoolefy\Support\Job\RedisDeadLetter;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

function parseReplayArgs(array $argv): array
{
    $opts = ['queue' => 'default', 'limit' => 1, 'mode' => null];
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--queue=')) {
            $opts['queue'] = substr($arg, 8);
        } elseif (str_starts_with($arg, '--limit=')) {
            $opts['limit'] = max(1, (int) substr($arg, 8));
        } elseif (str_starts_with($arg, '--mode=')) {
            $opts['mode'] = substr($arg, 7);
        }
    }

    return $opts;
}

$opts = parseReplayArgs($argv);
$mode = $opts['mode'] ?? getenv('JOB_REPLAY_MODE') ?: null;

// 已 bootstrap 应用时优先走业务队列；否则仅提示用法（stdout）。
$app = class_exists(\Swoolefy\Core\Application::class)
    ? \Swoolefy\Core\Application::getApp()
    : null;

if ($app === null || $mode === 'stdout') {
    // CLI 未挂载 App：无法取 redis/queue，提示在进程/HTTP 内调用 API
    fwrite(STDERR, "replay_dead_letter: no Application context — use RedisDeadLetter::replay() in app code, or bootstrap APP_PATH.\n");
    fwrite(STDERR, "Example in HTTP/process:\n");
    fwrite(STDERR, "  \$dlq = JobComponentFactory::redisDeadLetter(App::getRedis());\n");
    fwrite(STDERR, "  \$dlq->replay(fn (\$d) => App::getQueue()->push(\$d), 'default', 10);\n");
    exit(1);
}

try {
    $redis = $app->get('redis');
    // 组件可能是封装对象，优先取底层 redis 连接
    $redisObj = is_object($redis) && method_exists($redis, 'getObject') ? $redis->getObject() : $redis;
    $config = JobConfig::load();
    $dlq = RedisDeadLetter::fromConfig($redisObj, $config);
    $queue = $app->get('queue');

    // 从死信 List 弹出并重新 push 到业务队列
    $n = $dlq->replay(
        static function (array $data) use ($queue): void {
            $queue->push($data);
        },
        $opts['queue'],
        $opts['limit'],
    );

    echo "Replayed {$n} job(s) from dead letter queue={$opts['queue']}\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'replay failed: ' . $e->getMessage() . "\n");
    exit(1);
}
