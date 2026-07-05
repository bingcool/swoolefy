<?php

declare(strict_types=1);

namespace Swoolefy\Support\Security;

use RuntimeException;

/**
 * 出站 HTTP(S) URL 安全校验器。
 *
 * 用于 MCP Server url、LLM Provider baseUri 等出站连接，防止 SSRF /
 * 内网探测。配置来源：neuron_ai.php → security.outbound_url_allowlist。
 *
 * 校验顺序：
 *   1. URL 非空且可解析 host
 *   2. scheme 仅允许 http / https
 *   3. 默认拒绝 loopback / 私网 IP（除非 allowPrivateNetworks=true）
 *   4. 若配置了 allowlist，host 须匹配后缀白名单
 *
 * @see NeuronAiConfig::outboundUrlGuard()
 * @see McpFactory::assertConfigSafe()
 * @see NeuronProviderFactory::createFromParams()
 */
final class OutboundUrlGuard
{
    /**
     * @param list<string> $allowlistHostSuffixes host 后缀白名单，如 api.openai.com、.corp.internal
     * @param bool         $allowPrivateNetworks  true 时允许 127.0.0.1 / 10.x / 192.168.x 等
     */
    public function __construct(
        private readonly array $allowlistHostSuffixes = [],
        private readonly bool $allowPrivateNetworks = false,
    ) {
    }

    /**
     * 断言 URL 允许出站访问，否则抛 RuntimeException。
     *
     * @param string $context 错误上下文标识，如 mcp:docs、provider:OpenAI
     *
     * @throws RuntimeException
     */
    public function assertAllowed(string $url, string $context = 'outbound'): void
    {
        $url = trim($url);
        if ($url === '') {
            throw new RuntimeException("[{$context}] URL must not be empty");
        }

        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'])) {
            throw new RuntimeException("[{$context}] Invalid URL: {$url}");
        }

        $host = strtolower((string) $parts['host']);
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException("[{$context}] Unsupported URL scheme [{$scheme}]");
        }

        // 私网 / loopback 默认禁止，避免 MCP/LLM 配置指向内网敏感服务
        if (!$this->allowPrivateNetworks && $this->isPrivateOrLoopback($host)) {
            throw new RuntimeException("[{$context}] Private or loopback host is not allowed: {$host}");
        }

        // 配置了白名单时，host 必须命中至少一条后缀规则
        if ($this->allowlistHostSuffixes !== [] && !$this->matchesAllowlist($host)) {
            throw new RuntimeException("[{$context}] Host not in outbound allowlist: {$host}");
        }
    }

    /**
     * 后缀匹配：支持精确 host、.suffix 子域、无前缀 suffix。
     *
     * 例：allowlist 含 openai.com → api.openai.com 通过
     */
    private function matchesAllowlist(string $host): bool
    {
        foreach ($this->allowlistHostSuffixes as $suffix) {
            if (!is_string($suffix) || $suffix === '') {
                continue;
            }
            $suffix = strtolower(ltrim($suffix, '.'));
            if ($host === $suffix || str_ends_with($host, '.' . $suffix) || str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /** 判断 host 是否为 localhost 或 RFC1918 / link-local / loopback IP。 */
    private function isPrivateOrLoopback(string $host): bool
    {
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $this->isPrivateIpv4($host);
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $normalized = strtolower($host);

            return $normalized === '::1'
                || str_starts_with($normalized, 'fe80:')
                || str_starts_with($normalized, 'fc')
                || str_starts_with($normalized, 'fd');
        }

        return false;
    }

    /** IPv4 私网段：0/8、10/8、127/8、169.254/16、172.16/12、192.168/16。 */
    private function isPrivateIpv4(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        $long = ip2long($ip);
        if ($long === false) {
            return false;
        }

        $privateRanges = [
            ['0.0.0.0', '0.255.255.255'],
            ['10.0.0.0', '10.255.255.255'],
            ['127.0.0.0', '127.255.255.255'],
            ['169.254.0.0', '169.254.255.255'],
            ['172.16.0.0', '172.31.255.255'],
            ['192.168.0.0', '192.168.255.255'],
        ];

        foreach ($privateRanges as [$start, $end]) {
            if ($long >= ip2long($start) && $long <= ip2long($end)) {
                return true;
            }
        }

        return false;
    }
}
