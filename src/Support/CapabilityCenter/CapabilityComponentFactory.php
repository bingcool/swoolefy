<?php

declare(strict_types=1);

namespace Swoolefy\Support\CapabilityCenter;

use NeuronAI\Tools\ToolInterface;
use Swoolefy\Support\CapabilityCenter\Resolver\CompositeToolResolver;
use Swoolefy\Support\CapabilityCenter\Resolver\ToolResolverInterface;
use Swoolefy\Support\CapabilityCenter\Sync\McpCapabilitySync;
use Swoolefy\Support\Mcp\McpFactory;
use Swoolefy\Support\Neuron\NeuronAiConfig;

/**
 * CapabilityCenter 生产装配工厂。
 *
 * 对齐 McpComponentFactory / RagFactory 风格，负责组装：
 * Registry → Resolver → Materializer → CapabilityCenter 门面。
 *
 * Factory 有意保持生命周期简单：
 * - Registry 使用内存实现，并在当前 factory 实例内复用；
 * - CapabilityCenter 构建时可按配置同步一次 MCP 元数据；
 * - Native Tool 通过配置或构造参数显式注册。
 */
final class CapabilityComponentFactory
{
    /** Worker 级 Registry 缓存；同一 factory 实例内只初始化一次。 */
    private ?CapabilityRegistryInterface $registry = null;

    /**
     * @param McpFactory|null                                                     $mcpFactory        MCP 元数据同步与懒加载
     * @param NeuronAiConfig|null                                                 $config            capability 配置段
     * @param array<string, callable|ToolInterface|class-string<ToolInterface>> $nativeFactories   Native Tool 工厂映射
     * @param list<CapabilityDescriptor>                                          $nativeDescriptors 构造时显式注册的 descriptor
     */
    public function __construct(
        private readonly ?McpFactory $mcpFactory = null,
        private readonly ?NeuronAiConfig $config = null,
        private readonly array $nativeFactories = [],
        private readonly array $nativeDescriptors = [],
    ) {
    }

    /**
     * 获取或初始化 Registry。
     *
     * 首次调用时：创建 InMemoryCapabilityRegistry → 注册构造参数 descriptor
     * → 读取 neuron_ai.php capability.native_tools 配置段。
     */
    public function registry(): CapabilityRegistryInterface
    {
        if ($this->registry === null) {
            $this->registry = new InMemoryCapabilityRegistry();
            // 构造参数注入的 Native descriptor（测试 / 业务显式注册）
            $this->registry->registerBatch($this->nativeDescriptors);
            // 配置文件中的 capability.native_tools 段
            $this->registerNativeDescriptorsFromConfig($this->registry);
        }

        return $this->registry;
    }

    /** 创建默认解析器（policy filter + tag matcher + pinned merge）。 */
    public function resolver(): ToolResolverInterface
    {
        return new CompositeToolResolver($this->registry());
    }

    /**
     * 创建懒加载 Materializer。
     *
     * MCP 走 McpFactory；Native 走 nativeFactories 映射。
     */
    public function materializer(): LazyToolMaterializer
    {
        return new LazyToolMaterializer($this->mcpFactory, $this->nativeFactories);
    }

    /**
     * 创建 MCP 元数据同步器。
     *
     * 未注入 McpFactory 时返回 null（纯 Native 场景不需要 sync）。
     */
    public function mcpSync(): ?McpCapabilitySync
    {
        return $this->mcpFactory === null ? null : new McpCapabilitySync($this->mcpFactory, $this->registry());
    }

    /**
     * 组装完整的 CapabilityCenter 门面。
     *
     * MCP sync 仅在显式传入非空 $mcpServers 时执行，与 NeuronFactory::attachMcpTools()
     * 「未声明 server 则不挂载」对齐；禁止空列表回退为同步全部 server。
     *
     * @param list<string>|null $mcpServers 要同步的 MCP server 列表；null/空则跳过 MCP sync
     * @param string|null       $tenantId   同步时的租户上下文（写入 descriptor.tenantId）
     */
    public function capabilityCenter(?array $mcpServers = null, ?string $tenantId = null): CapabilityCenter
    {
        $config = $this->config ?? NeuronAiConfig::load();

        // boot 时仅同步调用方显式声明的 server，避免空 mcpServers 放开全量 MCP
        if ($config->capabilityMcpSyncOnBoot() && is_array($mcpServers) && $mcpServers !== []) {
            $this->mcpSync()?->syncServers($mcpServers, $tenantId);
        }

        return new CapabilityCenter(
            resolver: $this->resolver(),
            materializer: $this->materializer(),
            maxSchemaTools: $config->capabilityMaxSchemaTools(),
            debug: $config->capabilityDebug(),
        );
    }

    /**
     * 从 neuron_ai.php capability.native_tools 段注册 Native descriptor。
     *
     * 配置格式：
     * ```php
     * 'native_tools' => [
     *     'native:demo:weather' => [
     *         'name' => 'get_weather',
     *         'description' => '...',
     *         'tags' => ['weather'],
     *         'executor_ref' => 'native:demo:weather',
     *     ],
     * ]
     * ```
     */
    private function registerNativeDescriptorsFromConfig(CapabilityRegistryInterface $registry): void
    {
        $config = $this->config ?? NeuronAiConfig::load();
        $native = $config->capabilitySection()['native_tools'] ?? [];
        if (!is_array($native)) {
            return;
        }

        foreach ($native as $id => $section) {
            // 跳过非法配置项：id 须为非空字符串，值须为数组
            if (!is_string($id) || $id === '' || !is_array($section)) {
                continue;
            }

            $registry->register(new CapabilityDescriptor(
                id: $id,
                name: (string) ($section['name'] ?? $id),
                description: (string) ($section['description'] ?? $id),
                source: CapabilitySource::Native,
                tags: $this->stringList($section['tags'] ?? []),
                riskLevel: (string) ($section['risk_level'] ?? 'low'),
                tenantId: isset($section['tenant_id']) && is_string($section['tenant_id']) ? $section['tenant_id'] : null,
                requiredRoles: $this->stringList($section['required_roles'] ?? []),
                executorRef: (string) ($section['executor_ref'] ?? $id),
                enabled: (bool) ($section['enabled'] ?? true),
                metadata: is_array($section['metadata'] ?? null) ? $section['metadata'] : [],
            ));
        }
    }

    /**
     * 将配置中的字符串列表归一化为去重 list。
     *
     * @param mixed $raw 配置原始值
     *
     * @return list<string>
     */
    private function stringList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $list = [];
        foreach ($raw as $value) {
            if (is_string($value) && $value !== '' && !in_array($value, $list, true)) {
                $list[] = $value;
            }
        }

        return $list;
    }
}
