<?php

declare(strict_types=1);

namespace Swoolefy\Support\Mcp;

use PDO;
use RuntimeException;

/**
 * 基于关系库的 MCP Server 配置仓储。
 *
 * 表结构见 Schema/mcp_server_configs.sql，server_id 唯一。
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
    public function list(): array
    {
        $this->ensureSchema();

        $stmt = $this->pdo->query(
            "SELECT server_id, config_json, enabled, description
             FROM {$this->table}
             WHERE enabled = 1 AND deleted_at IS NULL",
        );

        $items = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $config = $this->rowToConfig($row);
            if ($config !== null) {
                $items[] = $config;
            }
        }

        return $items;
    }

    /** 按 server_id 查找单条全局配置。 */
    public function find(string $server_id): ?McpServerConfig
    {
        $this->ensureSchema();

        $stmt = $this->pdo->prepare(
            "SELECT server_id, config_json, enabled, description
             FROM {$this->table}
             WHERE server_id = :server_id AND deleted_at IS NULL LIMIT 1",
        );
        $stmt->execute([':server_id' => $server_id]);
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

        return new McpServerConfig(
            server_id: (string) ($row['server_id'] ?? ''),
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
                    config_json TEXT NOT NULL,
                    enabled INTEGER NOT NULL DEFAULT 1,
                    description TEXT NULL,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    deleted_at TEXT NULL,
                    PRIMARY KEY (server_id)
                )",
            ];
        }

        return [
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `server_id` VARCHAR(128) NOT NULL,
                `config_json` JSON NOT NULL,
                `enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `description` VARCHAR(255) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `deleted_at` DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `idx_mcp_server_configs_server_id` (`server_id`),
                KEY `idx_mcp_server_configs_enabled_deleted` (`enabled`, `deleted_at`)
            )",
        ];
    }
}
