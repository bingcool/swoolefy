-- MCP Server 全局基础配置表（MySQL / MariaDB / InnoDB）
-- 生产环境请手工执行后再启用 DbMcpServerConfigRepository
--
-- 用途：按 server_id 存储全局 Neuron McpConnector 配置 JSON

CREATE TABLE IF NOT EXISTS `mcp_server_configs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键',
    `server_id` VARCHAR(128) NOT NULL COMMENT 'MCP Server ID',
    `config_json` JSON NOT NULL COMMENT 'Neuron McpConnector 配置',
    `enabled` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '是否启用',
    `description` VARCHAR(255) NULL COMMENT '运维备注',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    `deleted_at` DATETIME DEFAULT NULL COMMENT '删除时间（软删）',
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_mcp_server_configs_server_id` (`server_id`),
    KEY `idx_mcp_server_configs_enabled_deleted` (`enabled`, `deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='MCP server global configs';
