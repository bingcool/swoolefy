<?php

declare(strict_types=1);

namespace Swoolefy\Support;

use RuntimeException;

/**
 * 多租户资源命名空间 —— RAG 知识库、Redis ChatHistory 等按 tenantId 隔离。
 *
 * tenantId 解析顺序：显式参数 → {@see FrameworkContext::getTenantId()}（x-tenant-id 透传头）。
 */
final class TenantScope
{
    public static function sanitize(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name) ?: 'default';
    }

    public static function resolveTenantId(?string $tenantId = null): ?string
    {
        if ($tenantId !== null && $tenantId !== '') {
            return $tenantId;
        }

        return FrameworkContext::getTenantId();
    }

    /**
     * 知识库物理名：{tenantId}_{kb}。
     *
     * @throws RuntimeException requireTenant 为 true 且 tenant 为空
     */
    public static function scopedKnowledgeBase(
        string $knowledgeBase,
        ?string $tenantId = null,
        bool $requireTenant = false,
    ): string {
        $kb = self::sanitize($knowledgeBase);
        $tenant = self::resolveTenantId($tenantId);
        if ($tenant === null || $tenant === '') {
            if ($requireTenant) {
                throw new RuntimeException(
                    'tenantId is required for knowledge base isolation; pass tenantId or set x-tenant-id header',
                );
            }

            return $kb;
        }

        return self::sanitize($tenant) . '_' . $kb;
    }

    /**
     * Redis ChatHistory key 前缀：chat:{tenantId}:thread:
     *
     * @throws RuntimeException requireTenant 为 true 且 tenant 为空
     */
    public static function redisChatKeyPrefix(
        ?string $tenantId = null,
        bool $requireTenant = false,
    ): string {
        $tenant = self::resolveTenantId($tenantId);
        if ($tenant === null || $tenant === '') {
            if ($requireTenant) {
                throw new RuntimeException(
                    'tenantId is required for Redis chat history isolation; pass tenantId or set x-tenant-id header',
                );
            }

            return 'chat:_global:thread:';
        }

        return 'chat:' . self::sanitize($tenant) . ':thread:';
    }

    /** 完整 Redis key：chat:{tenantId}:thread:{threadId} */
    public static function redisChatKey(
        string $threadId,
        ?string $tenantId = null,
        bool $requireTenant = false,
    ): string {
        return self::redisChatKeyPrefix($tenantId, $requireTenant) . $threadId;
    }
}
