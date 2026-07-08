<?php

declare(strict_types=1);

namespace Swoolefy\Support\CapabilityCenter;

/**
 * Capability 注册表契约。
 *
 * Registry 只保存工具元数据（CapabilityDescriptor），不持有真实 Tool 对象。
 * tenant、user、roles、当前 query 等请求态信息必须放在 ToolResolveContext 中，
 * 避免进程级缓存串请求。
 */
interface CapabilityRegistryInterface
{
    /** 注册或覆盖单个 descriptor（以 id 为键）。 */
    public function register(CapabilityDescriptor $descriptor): void;

    /**
     * 批量注册 descriptor。
     *
     * @param list<CapabilityDescriptor> $descriptors
     */
    public function registerBatch(array $descriptors): void;

    /** 按 ID 查找 descriptor；未命中返回 null。 */
    public function get(string $id): ?CapabilityDescriptor;

    /**
     * 返回全部已注册 descriptor（顺序不保证）。
     *
     * @return list<CapabilityDescriptor>
     */
    public function all(): array;

    /**
     * 按来源类型筛选 descriptor。
     *
     * @param CapabilitySource $source     来源类型（Mcp / Native 等）
     * @param string|null      $sourceName MCP 时传 server 名，其它来源可 null
     *
     * @return list<CapabilityDescriptor>
     */
    public function bySource(CapabilitySource $source, ?string $sourceName = null): array;
}
