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
 * Redis 实例来自 Swoolefy 组件容器（{@see \Swoolefy\Support\Workflow\WorkflowRedisResolver}），
 * phpredis / predis 包装类均继承 {@see RedisConnection}。
 *
 * 存储格式：JSON（{@see WorkflowRunSnapshot}），key = {prefix}{runId}
 *
 * CAS：{@see saveIfStatus()} 通过 Lua 脚本在 Redis 服务端原子比对 status 后写入。
 */
final class RedisRunStore implements RunStoreInterface, PauseTaskQueryableInterface
{
    /**
     * KEYS[1] = run key
     * ARGV[1] = expected status
     * ARGV[2] = new JSON payload
     * ARGV[3] = ttl seconds（0 表示 SET 不带过期）
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

    public function __construct(
        private readonly RedisConnection $redis,
        private readonly WorkflowRegistry $registry,
        private readonly string $prefix = 'workflow:run:',
        private readonly int $ttlSeconds = 0,
    ) {
    }

    /** {@inheritdoc} */
    public function save(WorkflowRun $run): void
    {
        $key = $this->key($run->runId);
        $json = json_encode(WorkflowRunSnapshot::fromRun($run)->toArray(), JSON_THROW_ON_ERROR);
        if ($this->ttlSeconds > 0) {
            $this->redis->setex($key, $this->ttlSeconds, $json);
        } else {
            $this->redis->set($key, $json);
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
                [$key, $expectedStatus->value, $json, (string) $this->ttlSeconds],
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

    /** {@inheritdoc} */
    public function find(string $runId): ?WorkflowRun
    {
        $raw = $this->redis->get($this->key($runId));
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
        $pattern = $this->prefix . '*';
        $ids = [];

        if ($this->redis instanceof Redis && method_exists($this->redis, 'getRedisInstance')) {
            $native = $this->redis->getRedisInstance();
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
            $result = $this->redis->scan($cursor, ['MATCH' => $pattern, 'COUNT' => 100]);
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
        if ($this->isPredisDriver()) {
            return $this->redis->eval($script, $numKeys, ...$keysAndArgs);
        }

        return $this->redis->eval($script, $keysAndArgs, $numKeys);
    }

    private function isPredisDriver(): bool
    {
        return $this->redis instanceof Predis;
    }
}
