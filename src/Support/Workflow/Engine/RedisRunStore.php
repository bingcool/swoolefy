<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Engine;

use Redis;
use Swoolefy\Support\Workflow\WorkflowRegistry;

/**
 * Redis Run 快照存储 —— 跨 Worker 持久化 Run，支持 resume / HITL。
 *
 * 存储格式：JSON（{@see WorkflowRunSnapshot}），key = {prefix}{runId}
 * CompiledWorkflow 不序列化，find 时通过 {@see WorkflowRegistry} 按 workflowId 重建。
 */
final class RedisRunStore implements RunStoreInterface, PauseTaskQueryableInterface
{
    public function __construct(
        private readonly Redis $redis,
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
        $iterator = null;

        do {
            $keys = $this->redis->scan($iterator, $pattern, 100);
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

    private function key(string $runId): string
    {
        return $this->prefix . $runId;
    }
}
