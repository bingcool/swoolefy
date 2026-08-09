<?php

declare(strict_types=1);

namespace Swoolefy\Core\Runtime\Memory;

/**
 * 容量有界的 Worker 本地内存观测历史。
 */
final class MemoryHistory
{
    /** @var list<MemorySnapshot> */
    private array $items = [];

    /** @throws \InvalidArgumentException 配置的历史容量不安全时抛出。 */
    public function __construct(private readonly int $maxSize)
    {
        if ($maxSize < 10) {
            throw new \InvalidArgumentException('Memory history size must be >= 10.');
        }
    }

    /** 添加观测值，必要时仅移除最早的一项。 */
    public function push(MemorySnapshot $snapshot): void
    {
        $this->items[] = $snapshot;
        if (count($this->items) > $this->maxSize) {
            array_shift($this->items);
        }
    }

    /** @return list<MemorySnapshot> */
    public function all(): array
    {
        return $this->items;
    }

    /** 在 Worker 正常退出时清理观测记录。 */
    public function clear(): void
    {
        $this->items = [];
    }
}
