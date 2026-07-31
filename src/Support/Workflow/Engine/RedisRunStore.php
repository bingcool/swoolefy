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

namespace Swoolefy\Support\Workflow\Engine;

use Swoolefy\Library\Redis\Predis;
use Swoolefy\Library\Redis\Redis;
use Swoolefy\Library\Redis\RedisConnection;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\WorkflowRegistry;
use Throwable;

/**
 * Redis Run 快照存储 —— 跨 Worker 持久化 Run，支持 resume / HITL。
 *
 * Redis 可直接注入 {@see RedisConnection}，或注入 `Closure(): RedisConnection`
 *（Factory 生产路径用后者：每次 IO 从当前 Application 取连接，避免进程级 Store 冻死首次 cid）。
 *
 * 存储格式：JSON（{@see WorkflowRunSnapshot}），key = {prefix}{runId}
 *
 * TTL：
 *   - 非 WAITING：使用构造参数 ttlSeconds（SETEX）；0 表示永不过期
 *   - WAITING：强制不设过期（SET），避免 HITL 长暂停期间 key 过期导致 resume 失败
 *
 * CAS：{@see saveIfStatus()} 通过 Lua 脚本在 Redis 服务端原子比对 status 后写入。
 */
final class RedisRunStore implements RunStoreInterface, PauseTaskQueryableInterface
{
    /**
     * KEYS[1] = run key
     * ARGV[1] = expected status
     * ARGV[2] = new JSON payload
     * ARGV[3] = ttl seconds（0 表示 SET 不带过期，并清除旧 TTL）
     *
     * @return int 1 = CAS 成功，0 = key 不存在或 status 不匹配
     */
    private const CAS_LUA = <<<'LUA'
local raw = redis.call('GET', KEYS[1])
if not raw then
    return 0
end
local ok, data = pcall(cjson.decode, raw)
if not ok or type(data) ~= 'table' or data.status ~= ARGV[1] then
    return 0
end
local ttl = tonumber(ARGV[3])
if ttl and ttl > 0 then
    redis.call('SETEX', KEYS[1], ttl, ARGV[2])
else
    redis.call('SET', KEYS[1], ARGV[2])
end
return 1
LUA;

    /**
     * @param RedisConnection|\Closure(): RedisConnection $redis 连接或按调用解析的 resolver
     */
    public function __construct(
        private readonly RedisConnection|\Closure $redis,
        private readonly WorkflowRegistry $registry,
        private readonly string $prefix = 'workflow:run:',
        private readonly int $ttlSeconds = 0,
    ) {
    }

    /** {@inheritdoc} */
    public function save(WorkflowRun $run): void
    {
        $redis = $this->connection();
        $key = $this->key($run->runId);
        $json = json_encode(WorkflowRunSnapshot::fromRun($run)->toArray(), JSON_THROW_ON_ERROR);
        $ttl = $this->ttlFor($run);
        if ($ttl > 0) {
            $redis->setex($key, $ttl, $json);
        } else {
            // WAITING 或 ttl=0：无过期写入；SET 会清除先前 SETEX 留下的 TTL
            $redis->set($key, $json);
        }
    }

    /**
     * CAS 条件更新 —— Lua 脚本在 Redis 服务端原子执行 GET + status 比对 + SET。
     *
     * {@inheritdoc}
     */
    public function saveIfStatus(WorkflowRun $run, RunStatus $expectedStatus): bool
    {
        $key = $this->key($run->runId);
        $json = json_encode(
            WorkflowRunSnapshot::fromRun($run)->toArray(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        );

        try {
            $result = $this->evalScript(
                self::CAS_LUA,
                [$key, $expectedStatus->value, $json, (string) $this->ttlFor($run)],
                1,
            );
        } catch (Throwable $e) {
            throw new WorkflowException(
                'Failed to CAS persist workflow run [' . $run->runId . ']: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        return (int) $result === 1;
    }

    /**
     * WAITING（HITL）永不因业务 TTL 过期；其余状态沿用 ttlSeconds。
     */
    private function ttlFor(WorkflowRun $run): int
    {
        if ($run->status === RunStatus::WAITING) {
            return 0;
        }

        return $this->ttlSeconds > 0 ? $this->ttlSeconds : 0;
    }

    /** {@inheritdoc} */
    public function find(string $runId): ?WorkflowRun
    {
        $raw = $this->connection()->get($this->key($runId));
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            return null;
        }

        return WorkflowRunSnapshot::fromArray($payload)->hydrate($this->registry);
    }

    /** {@inheritdoc} */
    public function listWaiting(?string $assignee = null): array
    {
        $waiting = [];
        foreach ($this->scanKeys() as $runId) {
            $run = $this->find($runId);
            if ($run === null || $run->status !== RunStatus::WAITING) {
                continue;
            }

            if ($assignee !== null && $assignee !== '') {
                $output = $run->state->outputOf((string) $run->pauseNodeId) ?? [];
                $taskAssignee = is_array($output) ? ($output['assignee'] ?? null) : null;
                if ($taskAssignee !== $assignee) {
                    continue;
                }
            }

            $waiting[] = $run;
        }

        return $waiting;
    }

    /** @return list<string> runId 列表 */
    private function scanKeys(): array
    {
        $redis = $this->connection();
        $pattern = $this->prefix . '*';
        $ids = [];

        if ($redis instanceof Redis && method_exists($redis, 'getRedisInstance')) {
            $native = $redis->getRedisInstance();
            if ($native instanceof \Redis) {
                $iterator = null;
                do {
                    $keys = $native->scan($iterator, $pattern, 100);
                    if ($keys === false) {
                        break;
                    }
                    foreach ($keys as $key) {
                        if (is_string($key) && str_starts_with($key, $this->prefix)) {
                            $ids[] = substr($key, strlen($this->prefix));
                        }
                    }
                } while ($iterator !== 0 && $iterator !== null);

                return $ids;
            }
        }

        $cursor = '0';
        do {
            $result = $redis->scan($cursor, ['MATCH' => $pattern, 'COUNT' => 100]);
            if (!is_array($result)) {
                break;
            }
            $nextCursor = (string) ($result[0] ?? '0');
            $keys = $result[1] ?? [];
            if (is_array($keys)) {
                foreach ($keys as $key) {
                    if (is_string($key) && str_starts_with($key, $this->prefix)) {
                        $ids[] = substr($key, strlen($this->prefix));
                    }
                }
            }
            $cursor = $nextCursor;
        } while ($cursor !== '0' && $cursor !== '');

        return $ids;
    }

    private function key(string $runId): string
    {
        return $this->prefix . $runId;
    }

    /**
     * @param list<string> $keysAndArgs [KEYS..., ARGV...]
     */
    private function evalScript(string $script, array $keysAndArgs, int $numKeys): mixed
    {
        $redis = $this->connection();
        if ($redis instanceof Predis) {
            return $redis->eval($script, $numKeys, ...$keysAndArgs);
        }

        return $redis->eval($script, $keysAndArgs, $numKeys);
    }

    /**
     * 每次 IO 取连接：Closure resolver 走当前协程 Application，禁止冻死首次 cid。
     */
    private function connection(): RedisConnection
    {
        $redis = $this->redis;
        if ($redis instanceof \Closure) {
            $resolved = $redis();
            if (!$resolved instanceof RedisConnection) {
                throw new WorkflowException(
                    'RedisRunStore resolver must return ' . RedisConnection::class,
                );
            }

            return $resolved;
        }

        return $redis;
    }
}
