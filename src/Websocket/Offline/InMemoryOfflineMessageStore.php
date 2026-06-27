<?php

namespace Swoolefy\Websocket\Offline;

/**
 * 进程内内存离线消息存储（单测 / 本地开发，**不可用于生产**）。
 *
 * 生产环境请实现 {@see OfflineMessageStoreInterface} 并配置 offline.store。
 */
class InMemoryOfflineMessageStore implements OfflineMessageStoreInterface
{
    /** @var array<string, array<int, array<string, mixed>>> userId => rows */
    private array $rows = [];

    private int $seq = 0;

    public function store(string $userId, string $event, array $data, array $meta = []): string
    {
        $id = (string) (++$this->seq);
        $this->rows[$userId][] = [
            'id' => $id,
            'event' => $event,
            'data' => $data,
            'trace_id' => (string) ($meta['trace_id'] ?? ''),
            'created_at' => (int) ($meta['created_at'] ?? time()),
            'delivered' => false,
        ];

        return $id;
    }

    public function fetchPending(string $userId, int $limit = 100, ?string $afterId = null): array
    {
        $limit = max(1, $limit);
        $afterNum = $afterId !== null && $afterId !== '' ? (int) $afterId : 0;
        $result = [];

        foreach ($this->rows[$userId] ?? [] as $row) {
            if (!empty($row['delivered'])) {
                continue;
            }
            if ((int) $row['id'] <= $afterNum) {
                continue;
            }
            $result[] = [
                'id' => (string) $row['id'],
                'event' => (string) $row['event'],
                'data' => is_array($row['data']) ? $row['data'] : [],
                'trace_id' => (string) ($row['trace_id'] ?? ''),
                'created_at' => (int) ($row['created_at'] ?? 0),
            ];
            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }

    public function markDelivered(string $userId, array $messageIds): int
    {
        if ($messageIds === [] || !isset($this->rows[$userId])) {
            return 0;
        }

        $lookup = array_fill_keys(array_map('strval', $messageIds), true);
        $count = 0;

        // 按下标写回，避免 foreach 引用在部分 PHP 版本下不生效
        foreach ($this->rows[$userId] as $index => $row) {
            $id = (string) ($row['id'] ?? '');
            if (isset($lookup[$id]) && empty($row['delivered'])) {
                $this->rows[$userId][$index]['delivered'] = true;
                $count++;
            }
        }

        return $count;
    }

    public function countPending(string $userId): int
    {
        $count = 0;
        foreach ($this->rows[$userId] ?? [] as $row) {
            if (empty($row['delivered'])) {
                $count++;
            }
        }

        return $count;
    }

    /** 单测重置 */
    public function resetForTest(): void
    {
        $this->rows = [];
        $this->seq = 0;
    }
}
