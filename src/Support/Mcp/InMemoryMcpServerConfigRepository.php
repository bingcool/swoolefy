<?php

declare(strict_types=1);

namespace Swoolefy\Support\Mcp;

/**
 * 内存 MCP 配置仓储 —— 单测与 HTTP 演示；生产替换为 DB 实现。
 *
 * 键策略：{tenantId|_global}:{serverId}，支持同 id 不同租户隔离。
 */
final class InMemoryMcpServerConfigRepository implements McpServerConfigRepositoryInterface
{
    /** @var array<string, McpServerConfig> */
    private array $configs = [];

    /** 插入或覆盖一条配置。 */
    public function upsert(McpServerConfig $config): void
    {
        $this->configs[$this->key($config->id, $config->tenantId)] = $config;
    }

    /** {@inheritdoc} */
    public function list(?string $tenantId = null): array
    {
        $items = [];
        foreach ($this->configs as $config) {
            if (!$config->enabled) {
                continue;
            }
            // 租户过滤：指定 tenant 时不返回其他 tenant 私有配置
            if ($tenantId !== null && $config->tenantId !== null && $config->tenantId !== $tenantId) {
                continue;
            }
            $items[] = $config;
        }

        return array_values($items);
    }

    /** {@inheritdoc} */
    public function find(string $id, ?string $tenantId = null): ?McpServerConfig
    {
        $direct = $this->configs[$this->key($id, $tenantId)] ?? null;
        if ($direct !== null) {
            return $direct;
        }

        // 回退：按 id 线性匹配（兼容 upsert 时 tenant 与查询 tenant 键不一致）
        foreach ($this->configs as $config) {
            if ($config->id === $id && ($tenantId === null || $config->tenantId === $tenantId)) {
                return $config;
            }
        }

        return null;
    }

    /** 复合主键：tenant + serverId。 */
    private function key(string $id, ?string $tenantId): string
    {
        return ($tenantId ?? '_global') . ':' . $id;
    }
}
