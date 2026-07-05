-- MCP Server 多租户配置表（MySQL / MariaDB / InnoDB）
-- 生产环境请手工执行后再启用 DbMcpServerConfigRepository
--
-- 用途：按 tenant_id + server_id 存储 Neuron McpConnector 配置 JSON

CREATE TABLE IF NOT EXISTS `mcp_server_configs` (
    `server_id` VARCHAR(128) NOT NULL COMMENT 'MCP Server ID',
    `tenant_id` VARCHAR(128) NOT NULL DEFAULT '' COMMENT '租户 ID；空串表示全局',
    `config_json` JSON NOT NULL COMMENT 'Neuron McpConnector 配置',
    `enabled` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '是否启用',
    `description` VARCHAR(255) NULL COMMENT '运维备注',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`server_id`, `tenant_id`),
    KEY `idx_mcp_server_configs_tenant_enabled` (`tenant_id`, `enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='MCP server configs per tenant';
