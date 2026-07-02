<?php

declare(strict_types=1);

namespace Swoolefy\Support\Mcp;

/**
 * MCP Server 配置 DTO —— 对应生产表 mcp_server_configs 一行。
 *
 * 字段映射：
 *   id        → Server 唯一标识（Agent mcpServers / API 路径参数）
 *   tenantId  → 多租户隔离；null 表示全局共享
 *   config    → 原样传给 Neuron McpConnector::make()
 *   enabled   → false 时 find/list 跳过
 *
 * @see swoolefyAI.md §4.11.4 mcp_server_configs
 */
final class McpServerConfig
{
    /**
     * @param string               $id          Server ID
     * @param string|null          $tenantId    租户 ID，null 为全局
     * @param array<string, mixed> $config      Neuron 配置（url+token 或 command+args）
     * @param bool                 $enabled     是否启用
     * @param string|null          $description 运维备注
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $tenantId,
        public readonly array $config,
        public readonly bool $enabled = true,
        public readonly ?string $description = null,
    ) {
    }

    /**
     * API 响应用脱敏视图 —— 隐藏 token / 密钥，防止 /mcp/servers 泄露凭证。
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        $config = $this->config;
        foreach (['token', 'key', 'apiKey', 'password', 'secret'] as $sensitive) {
            if (isset($config[$sensitive]) && is_string($config[$sensitive])) {
                $config[$sensitive] = '***';
            }
        }

        return [
            'id' => $this->id,
            'tenantId' => $this->tenantId,
            'enabled' => $this->enabled,
            'description' => $this->description,
            'transport' => self::detectTransport($this->config),
            'config' => $config,
        ];
    }

    /**
     * 从 Neuron 配置推断传输类型（http / sse / stdio / disabled）。
     *
     * @param array<string, mixed> $config
     */
    public static function detectTransport(array $config): string
    {
        if (isset($config['url']) && is_string($config['url'])) {
            return filter_var($config['async'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'sse' : 'http';
        }

        if (isset($config['command'])) {
            return 'stdio';
        }

        if (($config['transport'] ?? '') === 'disabled') {
            return 'disabled';
        }

        return 'unknown';
    }
}
