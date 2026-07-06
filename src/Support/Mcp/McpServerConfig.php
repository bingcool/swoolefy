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
 * Neuron MCP 配置约定：
 *   - 本地 stdio：['command' => 'php', 'args' => ['/path/server.php']]
 *   - 远程 HTTP：['url' => 'https://mcp.example.com', 'token' => '...']
 *   - 远程 SSE： ['url' => 'https://mcp.example.com', 'async' => true]
 *
 * @see docs/swoolefyAI.md §4.11.4 mcp_server_configs
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
        $config = self::maskSensitiveConfig($this->config);

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
     * Neuron 官方配置并不强制 transport 字段：
     * command 表示本地 stdio，url 表示远程 HTTP，url + async=true 表示 SSE。
     * 本方法也兼容本模块已有的显式 transport 标记，便于 API 列表展示。
     *
     * @param array<string, mixed> $config
     */
    public static function detectTransport(array $config): string
    {
        $transport = $config['transport'] ?? null;
        if (is_string($transport) && $transport !== '') {
            $transport = strtolower($transport);
            if (in_array($transport, ['http', 'sse', 'stdio', 'disabled'], true)) {
                return $transport;
            }
        }

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

    /**
     * 递归脱敏配置中的凭证。
     *
     * 远程 MCP 常见凭证既可能是顶层 token，也可能藏在 headers.Authorization、
     * headers.x-api-key 等嵌套字段里；公开 API 返回前必须统一遮蔽。
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private static function maskSensitiveConfig(array $config): array
    {
        $masked = [];
        foreach ($config as $key => $value) {
            $keyString = is_string($key) ? $key : (string) $key;
            if (self::isSensitiveKey($keyString)) {
                $masked[$key] = '***';
                continue;
            }

            $masked[$key] = is_array($value) ? self::maskSensitiveConfig($value) : $value;
        }

        return $masked;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', '_'], '', $key));

        return in_array($normalized, [
            'token',
            'key',
            'apikey',
            'password',
            'secret',
            'authorization',
            'xapikey',
        ], true);
    }
}
