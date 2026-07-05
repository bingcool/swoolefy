<?php

declare(strict_types=1);

namespace Swoolefy\Support\Security;

use RuntimeException;

/**
 * 出站 URL 安全校验 —— MCP / LLM baseUri 等。
 *
 * 默认拒绝私网 / loopback；host 须匹配 allowlist 后缀（若配置了 allowlist）。
 */
final class OutboundUrlGuard
{
    /** @param list<string> $allowlistHostSuffixes 如 api.openai.com、.internal.corp */
    public function __construct(
        private readonly array $allowlistHostSuffixes = [],
        private readonly bool $allowPrivateNetworks = false,
    ) {
    }

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

        if (!$this->allowPrivateNetworks && $this->isPrivateOrLoopback($host)) {
            throw new RuntimeException("[{$context}] Private or loopback host is not allowed: {$host}");
        }

        if ($this->allowlistHostSuffixes !== [] && !$this->matchesAllowlist($host)) {
            throw new RuntimeException("[{$context}] Host not in outbound allowlist: {$host}");
        }
    }

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
