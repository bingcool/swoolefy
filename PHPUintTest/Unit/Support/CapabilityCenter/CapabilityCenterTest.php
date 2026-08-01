<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

declare(strict_types=1);

namespace PHPUintTest\Unit\Support\CapabilityCenter;

/**
 * CapabilityCenter 模块回归测试。
 *
 * ## 覆盖范围
 * | 区域 | 要点 |
 * |------|------|
 * | PolicyToolFilter | tenant / role / risk / enabled 过滤 |
 * | CapabilityCenter | Top-K、pinned、MCP only/exclude、懒加载 materialize |
 * | CapabilityComponentFactory | 配置注册与组件装配 |
 * | McpCapabilitySync | MCP 能力同步到 Registry |
 */

use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolInterface;
use Swoolefy\Support\CapabilityCenter\CapabilityCenter;
use Swoolefy\Support\CapabilityCenter\CapabilityComponentFactory;
use Swoolefy\Support\CapabilityCenter\CapabilityDescriptor;
use Swoolefy\Support\CapabilityCenter\CapabilitySource;
use Swoolefy\Support\CapabilityCenter\InMemoryCapabilityRegistry;
use Swoolefy\Support\CapabilityCenter\LazyToolMaterializer;
use Swoolefy\Support\CapabilityCenter\Resolver\CompositeToolResolver;
use Swoolefy\Support\CapabilityCenter\Resolver\PolicyToolFilter;
use Swoolefy\Support\CapabilityCenter\Resolver\ToolResolveContext;
use Swoolefy\Support\CapabilityCenter\Sync\McpCapabilitySync;
use Swoolefy\Support\Mcp\McpFactory;
use Swoolefy\Support\Neuron\NeuronAiConfig;
use Swoolefy\Support\Security\OutboundUrlGuard;
use PHPUintTest\TestCase;

final class CapabilityCenterTest extends TestCase
{
    /**
     * 快速构造测试用 CapabilityDescriptor。
     *
     * @param string               $id    descriptor ID
     * @param list<string>         $tags  匹配标签
     * @param array<string, mixed> $extra 覆盖字段（source / tenantId / riskLevel 等）
     */
    private function makeCapabilityDescriptor(string $id, array $tags = [], array $extra = []): CapabilityDescriptor
    {
        return new CapabilityDescriptor(
            id: $id,
            name: (string) ($extra['name'] ?? basename(str_replace(':', '/', $id))),
            description: (string) ($extra['description'] ?? $id),
            source: $extra['source'] ?? CapabilitySource::Native,
            tags: $tags,
            riskLevel: (string) ($extra['riskLevel'] ?? 'low'),
            tenantId: $extra['tenantId'] ?? null,
            requiredRoles: $extra['requiredRoles'] ?? [],
            executorRef: (string) ($extra['executorRef'] ?? $id),
            mcpServer: $extra['mcpServer'] ?? null,
            enabled: $extra['enabled'] ?? true,
        );
    }

    /**
     * 验证 PolicyToolFilter 的 tenant / role / risk / enabled 过滤逻辑。
     *
     * 场景：4 个 descriptor 中只有 tenant+role 匹配的 native:ok 应通过。
     */
    public function testRegistryAndPolicyFilter(): void
    {
        $registry = new InMemoryCapabilityRegistry();
        $registry->registerBatch([
            $this->makeCapabilityDescriptor('native:ok', ['weather'], ['tenantId' => 't1', 'requiredRoles' => ['operator']]),
            $this->makeCapabilityDescriptor('native:wrong-tenant', ['weather'], ['tenantId' => 't2']),
            $this->makeCapabilityDescriptor('native:critical', ['weather'], ['riskLevel' => 'critical']),
            $this->makeCapabilityDescriptor('native:disabled', ['weather'], ['enabled' => false]),
        ]);

        $filtered = (new PolicyToolFilter())->filter($registry->all(), new ToolResolveContext(
            query: 'weather',
            agentId: 'test',
            tenantId: 't1',
            userId: 'u1',
            roles: ['operator'],
        ));

        $this->assertTrue(count($filtered) === 1, 'only allowed descriptor remains');
        $this->assertTrue($filtered[0]->id === 'native:ok', 'policy keeps matching descriptor');
    }

    /**
     * 验证 pinned 不占 topK quota，且 pinned 排在结果最前。
     *
     * 场景：topK=1，pinned=native:pinned，query 匹配 weather/date，结果应为 2 个。
     */
    public function testResolverTopKAndPinned(): void
    {
        $registry = new InMemoryCapabilityRegistry();
        $registry->registerBatch([
            $this->makeCapabilityDescriptor('native:pinned', ['audit']),
            $this->makeCapabilityDescriptor('native:weather', ['weather'], ['description' => 'Get city weather forecast']),
            $this->makeCapabilityDescriptor('native:date', ['date'], ['description' => 'Get current date']),
            $this->makeCapabilityDescriptor('native:math', ['math'], ['description' => 'Calculate numbers']),
        ]);

        $resolved = (new CompositeToolResolver($registry))->resolve(new ToolResolveContext(
            query: 'weather date',
            agentId: 'test',
            tenantId: null,
            userId: null,
            pinnedToolIds: ['native:pinned'],
            topK: 1,
        ));

        $this->assertTrue(count($resolved) === 2, 'pinned does not consume topK');
        $this->assertTrue($resolved[0]->descriptor->id === 'native:pinned', 'pinned first');
        $this->assertTrue($resolved[1]->descriptor->id === 'native:date' || $resolved[1]->descriptor->id === 'native:weather', 'one matched tool selected');
    }

    /**
     * 验证 mcpOnly / mcpExclude 静态过滤下沉到 Policy 阶段生效。
     *
     * 场景：github server 有两个 tool，only 白名单 + exclude 后只剩 search_code。
     */
    public function testMcpOnlyAndExcludePolicy(): void
    {
        $registry = new InMemoryCapabilityRegistry();
        $registry->registerBatch([
            $this->makeCapabilityDescriptor('mcp:github:search_code', ['github', 'search'], [
                'source' => CapabilitySource::Mcp,
                'mcpServer' => 'github',
                'name' => 'search_code',
            ]),
            $this->makeCapabilityDescriptor('mcp:github:delete_repo', ['github', 'delete'], [
                'source' => CapabilitySource::Mcp,
                'mcpServer' => 'github',
                'name' => 'delete_repo',
            ]),
        ]);

        $resolved = (new CompositeToolResolver($registry))->resolve(new ToolResolveContext(
            query: 'github',
            agentId: 'test',
            tenantId: null,
            userId: null,
            mcpServers: ['github'],
            topK: 10,
            mcpOnly: ['github' => ['search_code']],
            mcpExclude: ['github' => ['delete_repo']],
        ));

        $this->assertTrue(count($resolved) === 1, 'mcpOnly/mcpExclude restrict candidates');
        $this->assertTrue($resolved[0]->descriptor->name === 'search_code', 'search_code selected');
    }

    /**
     * 验证空 mcpServers 时 MCP Tool 全部被 Policy 过滤（对齐 attachMcpTools 空列表不挂载）。
     *
     * Native Tool 不受影响，仍可按 tag 匹配入选。
     */
    public function testEmptyMcpServersFiltersAllMcpTools(): void
    {
        $registry = new InMemoryCapabilityRegistry();
        $registry->registerBatch([
            $this->makeCapabilityDescriptor('mcp:github:search_code', ['github', 'search', 'code'], [
                'source' => CapabilitySource::Mcp,
                'mcpServer' => 'github',
                'name' => 'search_code',
                'description' => 'Search github code',
            ]),
            $this->makeCapabilityDescriptor('native:weather', ['weather'], [
                'description' => 'Get city weather',
            ]),
        ]);

        $filtered = (new PolicyToolFilter())->filter($registry->all(), new ToolResolveContext(
            query: 'search github code weather',
            agentId: 'test',
            tenantId: null,
            userId: null,
            mcpServers: [],
        ));

        $this->assertTrue(count($filtered) === 1, 'empty mcpServers drops all MCP tools');
        $this->assertTrue($filtered[0]->id === 'native:weather', 'native tool still allowed');

        $resolved = (new CompositeToolResolver($registry))->resolve(new ToolResolveContext(
            query: 'search github code',
            agentId: 'test',
            tenantId: null,
            userId: null,
            mcpServers: [],
            topK: 10,
        ));

        $this->assertTrue($resolved === [], 'resolver returns no MCP tools without mcpServers');
    }

    /**
     * 验证 Registry 按 (tenantId, id) 隔离，多租户 sync 同名 MCP tool 互不覆盖。
     */
    public function testRegistryTenantIsolation(): void
    {
        $registry = new InMemoryCapabilityRegistry();
        $registry->register($this->makeCapabilityDescriptor('mcp:github:search_code', ['github'], [
            'source' => CapabilitySource::Mcp,
            'mcpServer' => 'github',
            'name' => 'search_code',
            'tenantId' => 't1',
        ]));
        $registry->register($this->makeCapabilityDescriptor('mcp:github:search_code', ['github'], [
            'source' => CapabilitySource::Mcp,
            'mcpServer' => 'github',
            'name' => 'search_code',
            'tenantId' => 't2',
            'description' => 'tenant-b copy',
        ]));

        $this->assertTrue(count($registry->all()) === 2, 'both tenant descriptors coexist');
        $this->assertTrue($registry->get('mcp:github:search_code', 't1')?->tenantId === 't1', 't1 entry intact');
        $this->assertTrue($registry->get('mcp:github:search_code', 't2')?->description === 'tenant-b copy', 't2 entry intact');

        $forT1 = (new PolicyToolFilter())->filter($registry->all(), new ToolResolveContext(
            query: 'github',
            agentId: 'test',
            tenantId: 't1',
            userId: null,
            mcpServers: ['github'],
        ));
        $this->assertTrue(count($forT1) === 1, 't1 policy sees one tool');
        $this->assertTrue($forT1[0]->tenantId === 't1', 't1 policy keeps own tenant');

        $forT2 = (new PolicyToolFilter())->filter($registry->all(), new ToolResolveContext(
            query: 'github',
            agentId: 'test',
            tenantId: 't2',
            userId: null,
            mcpServers: ['github'],
        ));
        $this->assertTrue(count($forT2) === 1 && $forT2[0]->tenantId === 't2', 't2 policy keeps own tenant');
    }

    /**
     * 验证 CapabilityCenter 只 materialize 命中的 Tool，未选中不触发工厂。
     *
     * 通过 $created 数组记录实际调用 materialize 的 descriptor id。
     */
    public function testCapabilityCenterMaterializesOnlyResolvedTools(): void
    {
        $registry = new InMemoryCapabilityRegistry();
        $registry->registerBatch([
            $this->makeCapabilityDescriptor('native:weather', ['weather'], ['description' => 'Get weather']),
            $this->makeCapabilityDescriptor('native:math', ['math'], ['description' => 'Do math']),
        ]);

        $created = [];
        $center = new CapabilityCenter(
            resolver: new CompositeToolResolver($registry),
            materializer: new LazyToolMaterializer(nativeFactories: [
                'native:weather' => static function (CapabilityDescriptor $descriptor) use (&$created): ToolInterface {
                    $created[] = $descriptor->id;
                    return Tool::make('get_weather', 'Get weather');
                },
                'native:math' => static function (CapabilityDescriptor $descriptor) use (&$created): ToolInterface {
                    $created[] = $descriptor->id;
                    return Tool::make('calculate', 'Calculate');
                },
            ]),
            maxSchemaTools: 20,
        );

        $tools = $center->resolveTools(new ToolResolveContext(
            query: 'weather',
            agentId: 'test',
            tenantId: null,
            userId: null,
            topK: 1,
        ));

        $this->assertTrue(count($tools) === 1, 'one tool materialized');
        $this->assertTrue($tools[0]->getName() === 'get_weather', 'weather tool materialized');
        $this->assertTrue($created === ['native:weather'], 'unselected tool was not materialized');
    }

    /**
     * maxSchemaTools 截断时优先保留 pinned，不被 matched 挤掉。
     *
     * 场景：maxSchemaTools=2，2 个 pinned + 多个 matched → 结果仅为 2 个 pinned。
     */
    public function testMaxSchemaToolsPrefersPinned(): void
    {
        $registry = new InMemoryCapabilityRegistry();
        $registry->registerBatch([
            $this->makeCapabilityDescriptor('native:pin_a', ['audit'], ['description' => 'Pinned A']),
            $this->makeCapabilityDescriptor('native:pin_b', ['audit'], ['description' => 'Pinned B']),
            $this->makeCapabilityDescriptor('native:weather', ['weather'], ['description' => 'Get weather forecast']),
            $this->makeCapabilityDescriptor('native:date', ['date'], ['description' => 'Get current date']),
        ]);

        $center = new CapabilityCenter(
            resolver: new CompositeToolResolver($registry),
            materializer: new LazyToolMaterializer(nativeFactories: [
                'native:pin_a' => static fn () => Tool::make('pin_a', 'Pinned A'),
                'native:pin_b' => static fn () => Tool::make('pin_b', 'Pinned B'),
                'native:weather' => static fn () => Tool::make('get_weather', 'Get weather'),
                'native:date' => static fn () => Tool::make('get_date', 'Get date'),
            ]),
            maxSchemaTools: 2,
        );

        $tools = $center->resolveTools(new ToolResolveContext(
            query: 'weather date',
            agentId: 'test',
            tenantId: null,
            userId: null,
            pinnedToolIds: ['native:pin_a', 'native:pin_b'],
            topK: 2,
        ));

        $names = array_map(static fn (ToolInterface $t) => $t->getName(), $tools);
        $this->assertTrue(count($tools) === 2, 'respects maxSchemaTools');
        $this->assertTrue($names === ['pin_a', 'pin_b'], 'both pinned kept; matched truncated');
    }

    /**
     * pinned 未占满预算时，剩余名额给 matched。
     */
    public function testMaxSchemaToolsKeepsPinnedThenMatched(): void
    {
        $registry = new InMemoryCapabilityRegistry();
        $registry->registerBatch([
            $this->makeCapabilityDescriptor('native:pin_a', ['audit'], ['description' => 'Pinned A']),
            $this->makeCapabilityDescriptor('native:weather', ['weather'], ['description' => 'Get weather forecast']),
            $this->makeCapabilityDescriptor('native:date', ['date'], ['description' => 'Get current date']),
        ]);

        $center = new CapabilityCenter(
            resolver: new CompositeToolResolver($registry),
            materializer: new LazyToolMaterializer(nativeFactories: [
                'native:pin_a' => static fn () => Tool::make('pin_a', 'Pinned A'),
                'native:weather' => static fn () => Tool::make('get_weather', 'Get weather'),
                'native:date' => static fn () => Tool::make('get_date', 'Get date'),
            ]),
            maxSchemaTools: 2,
        );

        $tools = $center->resolveTools(new ToolResolveContext(
            query: 'weather date',
            agentId: 'test',
            tenantId: null,
            userId: null,
            pinnedToolIds: ['native:pin_a'],
            topK: 2,
        ));

        $names = array_map(static fn (ToolInterface $t) => $t->getName(), $tools);
        $this->assertTrue(count($tools) === 2, 'fills remaining slot');
        $this->assertTrue($names[0] === 'pin_a', 'pinned first');
        $this->assertTrue(in_array($names[1], ['get_weather', 'get_date'], true), 'one matched fills budget');
    }

    /**
     * 验证 NeuronAiConfig capability 段读取与 CapabilityComponentFactory 配置注册 Native descriptor。
     */
    public function testCapabilityConfigAndFactoryNativeDescriptors(): void
    {
        $config = NeuronAiConfig::fromArray([
            'capability' => [
                'enabled' => true,
                'default_top_k' => 3,
                'max_schema_tools' => 7,
                'mcp_sync_on_boot' => false,
                'native_tools' => [
                    'native:demo:weather' => [
                        'name' => 'get_weather',
                        'description' => 'Get demo weather',
                        'tags' => ['weather', 'demo'],
                        'executor_ref' => 'native:demo:weather',
                    ],
                ],
            ],
        ]);

        $this->assertTrue($config->capabilityEnabled(), 'capability enabled');
        $this->assertTrue($config->capabilityDefaultTopK() === 3, 'topK config');
        $this->assertTrue($config->capabilityMaxSchemaTools() === 7, 'max schema tools config');

        $factory = new CapabilityComponentFactory(config: $config, nativeFactories: [
            'native:demo:weather' => static fn (): ToolInterface => Tool::make('get_weather', 'Get demo weather'),
        ]);
        $tools = $factory->capabilityCenter()->resolveTools(new ToolResolveContext(
            query: 'weather',
            agentId: 'test',
            tenantId: null,
            userId: null,
            topK: 3,
        ));

        $this->assertTrue(count($tools) === 1, 'factory registered native descriptor');
        $this->assertTrue($tools[0]->getName() === 'get_weather', 'factory materialized native tool');
    }

    /**
     * 验证 unregister / unregisterBySource：按 tenant + mcpServer 精确清理，不误删其它条目。
     */
    public function testRegistryUnregisterBySource(): void
    {
        $registry = new InMemoryCapabilityRegistry();
        $registry->registerBatch([
            $this->makeCapabilityDescriptor('mcp:docs:old', ['docs'], [
                'source' => CapabilitySource::Mcp,
                'mcpServer' => 'docs',
                'tenantId' => null,
            ]),
            $this->makeCapabilityDescriptor('mcp:docs:keep_other_tenant', ['docs'], [
                'source' => CapabilitySource::Mcp,
                'mcpServer' => 'docs',
                'tenantId' => 't1',
            ]),
            $this->makeCapabilityDescriptor('mcp:github:search', ['github'], [
                'source' => CapabilitySource::Mcp,
                'mcpServer' => 'github',
                'tenantId' => null,
            ]),
            $this->makeCapabilityDescriptor('native:weather', ['weather']),
        ]);

        $this->assertTrue($registry->unregister('mcp:docs:old', null) === true, 'unregister existing');
        $this->assertTrue($registry->get('mcp:docs:old') === null, 'global docs old removed');
        $this->assertTrue($registry->get('mcp:docs:keep_other_tenant', 't1') !== null, 'other tenant kept after single unregister');

        // 重新放入全局 docs 幽灵，再按 source 清理
        $registry->register($this->makeCapabilityDescriptor('mcp:docs:ghost', ['docs'], [
            'source' => CapabilitySource::Mcp,
            'mcpServer' => 'docs',
            'tenantId' => null,
        ]));

        $removed = $registry->unregisterBySource(CapabilitySource::Mcp, 'docs', null);
        $this->assertTrue($removed === 1, 'only global docs mcp removed');
        $this->assertTrue($registry->get('mcp:docs:ghost') === null, 'ghost cleared');
        $this->assertTrue($registry->get('mcp:docs:keep_other_tenant', 't1') !== null, 'tenant docs kept');
        $this->assertTrue($registry->get('mcp:github:search') !== null, 'other server kept');
        $this->assertTrue($registry->get('native:weather') !== null, 'native kept');
    }

    /**
     * 验证 McpCapabilitySync 成功后清理已删除 tool（空列表 = 该 server 全清）。
     */
    public function testMcpCapabilitySyncRemovesDeletedTools(): void
    {
        $registry = new InMemoryCapabilityRegistry();
        $registry->registerBatch([
            $this->makeCapabilityDescriptor('mcp:docs:deleted_tool', ['docs'], [
                'source' => CapabilitySource::Mcp,
                'mcpServer' => 'docs',
                'name' => 'deleted_tool',
                'tenantId' => null,
            ]),
            $this->makeCapabilityDescriptor('mcp:github:search', ['github'], [
                'source' => CapabilitySource::Mcp,
                'mcpServer' => 'github',
                'name' => 'search',
                'tenantId' => null,
            ]),
            $this->makeCapabilityDescriptor('native:weather', ['weather']),
        ]);

        // 无配置的 server → discoverToolNames 返回空 → sync 成功并清空该 server 旧 descriptor
        $sync = new McpCapabilitySync(new McpFactory(servers: []), $registry);
        $count = $sync->syncServer('docs');

        $this->assertTrue($count === 0, 'empty tool list sync returns 0');
        $this->assertTrue($registry->get('mcp:docs:deleted_tool') === null, 'deleted mcp tool ghost removed');
        $this->assertTrue($registry->get('mcp:github:search') !== null, 'other mcp server untouched');
        $this->assertTrue($registry->get('native:weather') !== null, 'native untouched');
    }

    /**
     * 验证 MCP 连接失败时 sync 不清空已有 descriptor（与成功空列表区分）。
     */
    public function testMcpCapabilitySyncKeepsDescriptorsOnDiscoverFailure(): void
    {
        $registry = new InMemoryCapabilityRegistry();
        $registry->register($this->makeCapabilityDescriptor('mcp:docs:existing', ['docs'], [
            'source' => CapabilitySource::Mcp,
            'mcpServer' => 'docs',
            'name' => 'existing',
            'tenantId' => null,
        ]));

        // 配置了私网 URL 且守卫拒绝 → discover 在连接前抛错 → sync 保留旧条目
        $sync = new McpCapabilitySync(new McpFactory(
            servers: [
                'docs' => [
                    'url' => 'http://127.0.0.1:9/mcp',
                    'token' => 'x',
                ],
            ],
            urlGuard: new OutboundUrlGuard(
                allowlistHostSuffixes: [],
                allowPrivateNetworks: false,
            ),
        ), $registry);

        $count = $sync->syncServer('docs');
        $this->assertTrue($count === 0, 'failed discover returns 0');
        $this->assertTrue($registry->get('mcp:docs:existing') !== null, 'existing descriptor kept on failure');
    }
}
