<?php

declare(strict_types=1);

namespace Swoolefy\Support\Mcp;

use RuntimeException;

/**
 * 本地 stdio MCP 安全守卫。
 *
 * stdio 传输会启动子进程执行任意 command，生产环境默认禁用。
 * 配置：neuron_ai.php → mcp.allow_stdio、mcp.stdio_command_allowlist。
 *
 * 校验逻辑（仅对本地 stdio 配置生效）：
 *   1. allow_stdio=false → 直接拒绝
 *   2. allow_stdio=true 但 allowlist 为空 → 拒绝（防全开）
 *   3. command 基名不在 allowlist → 拒绝
 *
 * @see McpFactory::assertConfigSafe()
 * @see McpProcessRunner::isLocalStdioConfig()
 */
final class McpStdioGuard
{
    /**
     * @param bool         $allowStdio        是否允许 stdio MCP（生产默认 false）
     * @param list<string> $commandAllowlist  允许的 command 基名，如 npx、node、uvx
     */
    public function __construct(
        private readonly bool $allowStdio,
        private readonly array $commandAllowlist = [],
    ) {
    }

    /**
     * 断言 MCP 配置允许连接；非 stdio 配置直接 return。
     *
     * @param array<string, mixed> $config     MCP 连接配置（含 transport / command）
     * @param string               $serverName 服务名，用于错误信息
     *
     * @throws RuntimeException
     */
    public function assertAllowed(array $config, string $serverName = 'mcp'): void
    {
        if (!McpProcessRunner::isLocalStdioConfig($config)) {
            return;
        }

        if (!$this->allowStdio) {
            throw new RuntimeException(
                "Local stdio MCP [{$serverName}] is disabled in production. Use HTTP/SSE MCP or set mcp.allow_stdio=true for dev only.",
            );
        }

        $command = (string) ($config['command'] ?? '');
        $base = basename($command);
        if ($this->commandAllowlist === []) {
            throw new RuntimeException(
                "stdio MCP [{$serverName}] requires mcp.stdio_command_allowlist when allow_stdio is enabled",
            );
        }

        if (!in_array($base, $this->commandAllowlist, true)) {
            throw new RuntimeException(
                "stdio MCP command [{$base}] is not in allowlist for server [{$serverName}]",
            );
        }
    }
}
