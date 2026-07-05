<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Engine;

use Swoolefy\Library\Redis\Redis;
use Swoolefy\Library\Redis\RedisConnection;
use Swoolefy\Support\Workflow\WorkflowRegistry;

/**
 * Redis Run 快照存储 —— 跨 Worker 持久化 Run，支持 resume / HITL。
 *
 * Redis 实例来自 Swoolefy 组件容器（{@see \Swoolefy\Support\Workflow\WorkflowRedisResolver}），
 * phpredis / predis 包装类均继承 {@see RedisConnection}。
 *
 * 存储格式：JSON（{@see WorkflowRunSnapshot}），key = {prefix}{runId}
 */
final class RedisRunStore implements RunStoreInterface, PauseTaskQueryableInterface
{
    public function __construct(
        private readonly RedisConnection $redis,
        private readonly WorkflowRegistry $registry,
        private readonly string $prefix = 'workflow:run:',
        private readonly int $ttlSeconds = 86400,
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
     * CAS 条件更新 —— 读-改-写语义（Redis 无原生 CAS 时用 find 比对 status）。
     *
     * 注意：高并发下存在极小竞态窗口；生产 HITL 高并发场景优先 DbRunStore。
     *
     * {@inheritdoc}
     */
    public function saveIfStatus(WorkflowRun $run, RunStatus $expectedStatus): bool
    {
        $existing = $this->find($run->runId);
        if ($existing === null || $existing->status !== $expectedStatus) {
            return false;
        }

        $this->save($run);

        return true;
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
}
