<?php

declare(strict_types=1);

namespace Swoolefy\Support\Mcp;

use RuntimeException;

/**
 * 本地 stdio MCP 安全守卫 —— 生产默认禁用或命令 allowlist。
 */
final class McpStdioGuard
{
    /** @param list<string> $commandAllowlist 允许的 command 基名，如 npx、node */
    public function __construct(
        private readonly bool $allowStdio,
        private readonly array $commandAllowlist = [],
    ) {
    }

    /** @param array<string, mixed> $config */
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
