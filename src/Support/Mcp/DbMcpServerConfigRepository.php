<?php

declare(strict_types=1);

namespace Swoolefy\Support\Mcp;

use PDO;
use RuntimeException;

/**
 * 基于关系库的 MCP Server 配置仓储。
 *
 * 表结构见 Schema/mcp_server_configs.sql，主键 (server_id, tenant_id)。
 *
 * 租户模型：
 *   - tenant_id 非空 — 租户专属配置，McpFactory 在 tenantId 匹配时优先使用
 *   - tenant_id 空字符串 — 全局配置，对所有租户可见（优先级低于租户专属）
 *
 * list() 行为：
 *   - 传 tenantId — 返回该租户行 + 全局行（enabled=1）
 *   - 不传 tenantId — 返回全部 enabled 行
 *
 * @see McpFactory::resolveConfig()
 */
final class DbMcpServerConfigRepository implements McpServerConfigRepositoryInterface
{
    private bool $schemaReady = false;

    /**
     * @param bool $autoMigrate 仅单测使用；生产须预执行 Schema/mcp_server_configs.sql
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $table = 'mcp_server_configs',
        private readonly bool $autoMigrate = false,
    ) {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $this->table)) {
            throw new RuntimeException("Invalid MCP config table name [{$this->table}]");
        }
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * 列出可用 MCP 配置。
     *
     * @return list<McpServerConfig>
     */
    public function list(?string $tenantId = null): array
    {
        $this->ensureSchema();

        if ($tenantId !== null && $tenantId !== '') {
            // 租户上下文：专属 + 全局 fallback
            $stmt = $this->pdo->prepare(
                "SELECT server_id, tenant_id, config_json, enabled, description
                 FROM {$this->table}
                 WHERE enabled = 1 AND (tenant_id = :tenant_id OR tenant_id = '')",
            );
            $stmt->execute([':tenant_id' => $tenantId]);
        } else {
            $stmt = $this->pdo->query(
                "SELECT server_id, tenant_id, config_json, enabled, description
                 FROM {$this->table} WHERE enabled = 1",
            );
        }

        $items = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $config = $this->rowToConfig($row);
            if ($config !== null) {
                $items[] = $config;
            }
        }

        return $items;
    }

    /**
     * 按 server_id + tenant 查找单条配置。
     *
     * 查找顺序（与 McpFactory 一致）：
     *   1. tenantId 非空 → 查租户专属行
     *   2. 查 tenant_id='' 的全局行
     */
    public function find(string $id, ?string $tenantId = null): ?McpServerConfig
    {
        $this->ensureSchema();

        if ($tenantId !== null && $tenantId !== '') {
            $stmt = $this->pdo->prepare(
                "SELECT server_id, tenant_id, config_json, enabled, description
                 FROM {$this->table}
                 WHERE server_id = :server_id AND tenant_id = :tenant_id LIMIT 1",
            );
            $stmt->execute([':server_id' => $id, ':tenant_id' => $tenantId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                return $this->rowToConfig($row);
            }
        }

        $stmt = $this->pdo->prepare(
            "SELECT server_id, tenant_id, config_json, enabled, description
             FROM {$this->table}
             WHERE server_id = :server_id AND tenant_id = '' LIMIT 1",
        );
        $stmt->execute([':server_id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->rowToConfig($row) : null;
    }

    /** 将 DB 行反序列化为 McpServerConfig；JSON 非法时返回 null 跳过。 */
    private function rowToConfig(array $row): ?McpServerConfig
    {
        $json = $row['config_json'] ?? '';
        if (!is_string($json) || $json === '') {
            return null;
        }

        try {
            $config = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($config)) {
            return null;
        }

        $tenantId = (string) ($row['tenant_id'] ?? '');
        $tenant = $tenantId === '' ? null : $tenantId;

        return new McpServerConfig(
            id: (string) ($row['server_id'] ?? ''),
            tenantId: $tenant,
            config: $config,
            enabled: (bool) ($row['enabled'] ?? true),
            description: isset($row['description']) ? (string) $row['description'] : null,
        );
    }

    /** 确保表存在；生产依赖预建表，autoMigrate 仅供单测 SQLite。 */
    private function ensureSchema(): void
    {
        if ($this->schemaReady) {
            return;
        }

        if ($this->autoMigrate) {
            $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            foreach ($this->migrateStatements($driver) as $sql) {
                $this->pdo->exec($sql);
            }
        }

        $this->schemaReady = true;
    }

    /** @return list<string> */
    private function migrateStatements(string $driver): array
    {
        $table = $this->table;

        if ($driver === 'sqlite') {
            return [
                "CREATE TABLE IF NOT EXISTS {$table} (
                    server_id TEXT NOT NULL,
                    tenant_id TEXT NOT NULL DEFAULT '',
                    config_json TEXT NOT NULL,
                    enabled INTEGER NOT NULL DEFAULT 1,
                    description TEXT NULL,
                    PRIMARY KEY (server_id, tenant_id)
                )",
            ];
        }

        return [
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `server_id` VARCHAR(128) NOT NULL,
                `tenant_id` VARCHAR(128) NOT NULL DEFAULT '',
                `config_json` JSON NOT NULL,
                `enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `description` VARCHAR(255) NULL,
                PRIMARY KEY (`server_id`, `tenant_id`)
            )",
        ];
    }
}
