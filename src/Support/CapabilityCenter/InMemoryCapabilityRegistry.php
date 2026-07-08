<?php

declare(strict_types=1);

namespace Swoolefy\Support\CapabilityCenter;

/**
 * Worker 本地内存 Capability 注册表。
 *
 * 生产说明：
 * - 该缓存有意保持为进程本地、仅存元数据；
 * - Worker reload 后可通过 MCP 同步 / 显式 Native 注册重建；
 * - MCP 同步失败时不能清空已有 descriptor，调用方可继续使用上一份可用元数据。
 */
final class InMemoryCapabilityRegistry implements CapabilityRegistryInterface
{
    /** @var array<string, CapabilityDescriptor> 以 descriptor.id 为键的内存索引 */
    private array $descriptors = [];

    /**
     * 注册或覆盖单个 descriptor。
     *
     * 相同 id 再次 register 会覆盖旧值（MCP 重新 sync 时用于更新元数据）。
     */
    public function register(CapabilityDescriptor $descriptor): void
    {
        $this->descriptors[$descriptor->id] = $descriptor;
    }

    /**
     * 批量注册 descriptor。
     *
     * 跳过非 CapabilityDescriptor 实例，避免配置错误导致整批失败。
     *
     * @param list<CapabilityDescriptor> $descriptors
     */
    public function registerBatch(array $descriptors): void
    {
        foreach ($descriptors as $descriptor) {
            if ($descriptor instanceof CapabilityDescriptor) {
                $this->register($descriptor);
            }
        }
    }

    /** 按 ID 查找；未命中返回 null。 */
    public function get(string $id): ?CapabilityDescriptor
    {
        return $this->descriptors[$id] ?? null;
    }

    /**
     * 返回全部 descriptor 列表。
     *
     * array_values 去掉关联键，保证返回 list 语义。
     *
     * @return list<CapabilityDescriptor>
     */
    public function all(): array
    {
        return array_values($this->descriptors);
    }

    /**
     * 按来源类型筛选 descriptor。
     *
     * @param CapabilitySource $source     来源类型
     * @param string|null      $sourceName MCP 时匹配 mcpServer 字段；null 时不限 server
     *
     * @return list<CapabilityDescriptor>
     */
    public function bySource(CapabilitySource $source, ?string $sourceName = null): array
    {
        $items = [];
        foreach ($this->descriptors as $descriptor) {
            // 来源类型不匹配则跳过
            if ($descriptor->source !== $source) {
                continue;
            }
            // MCP 且指定了 server 名时，须精确匹配 mcpServer
            if ($sourceName !== null && $descriptor->mcpServer !== $sourceName) {
                continue;
            }
            $items[] = $descriptor;
        }

        return $items;
    }
}
