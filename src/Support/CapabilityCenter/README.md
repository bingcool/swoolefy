# CapabilityCenter — Tool 能力中心

在企业级 Agent 场景下，当 MCP / Native Tool 数量达到几十、上百甚至更多时，**全量注入**会带来：

- LLM schema token 爆炸（每个 Tool 的 name / description / parameters 都送入模型）
- 模型在相似 Tool 之间误选
- MCP 建连、`tools/list`、序列化带来的首 token 延迟上升

`CapabilityCenter` 在 Neuron `Agent::addTool()` 之前增加一层**轻量筛选**：

1. **Registry** 保存全量 Tool 元数据（不持有真实 Tool 对象）
2. **Resolver** 按租户、角色、query、profile 筛选 Top-K
3. **Materializer** 只把命中的少量 Tool 懒加载成 `ToolInterface`

最终仍只给 Agent 注入 Neuron 原生 `ToolInterface[]`，不 fork Neuron，不替代 MCP。

- 详细设计：[docs/CapabilityTool.md](../../../docs/CapabilityTool.md)
- 配置：`Config/neuron_ai.php` → `capability` 段
- 接入点：`Support/Neuron/NeuronFactory.php`（`attachTools()`）
- 演示：`Test/Module/Agent/CapabilityWeatherDemo.php`、`AgentCapabilityController.php`

---

## 什么时候用

| 场景 | 推荐方式 |
|------|----------|
| Tool ≤ 20，固定子集 | Agent `tools()` 或 `AINodeBuilder::mcp()` 全量挂载 |
| 固定白名单 | `mcpOnly` / `mcpExclude` |
| **100+ Tool，每轮动态意图** | **CapabilityCenter**（本模块） |
| 语义相似 Tool 很多 | Phase 4 embedding（后续） |

**默认关闭**：`CAPABILITY_ENABLED=false` 时，`NeuronFactory` 行为与改造前完全一致（`McpFactory::tools()` 全量挂载）。

---

## 目录结构

```
CapabilityCenter/
├── CapabilitySource.php              # 来源枚举：Mcp / Native / Api / Db / Workflow
├── CapabilityDescriptor.php          # 元数据契约（id / name / tags / executorRef …）
├── CapabilityRegistryInterface.php
├── InMemoryCapabilityRegistry.php    # Worker 级内存 Registry（Phase 3）
├── CapabilityCenter.php              # 门面：resolve → materialize → ToolInterface[]
├── CapabilityComponentFactory.php    # 生产装配工厂
├── LazyToolMaterializer.php          # descriptor → Neuron Tool 懒加载
├── Sync/
│   └── McpCapabilitySync.php         # MCP tool 元数据同步到 Registry
├── Resolver/
│   ├── ToolResolveContext.php        # 请求态上下文（query / topK / pinned …）
│   ├── ResolvedCapability.php
│   ├── ToolResolverInterface.php
│   ├── PolicyToolFilter.php          # 确定性策略过滤
│   ├── TagToolMatcher.php            # 轻量 tag / query 打分
│   └── CompositeToolResolver.php     # 默认流水线
└── Tests/
    └── CapabilityCenterTest.php
```

---

## 核心原理

```
用户消息 / nodeConfig
        │
        ▼
 ToolResolveContext（请求态，禁止放 static）
        │
        ▼
 Registry.all() ──► PolicyToolFilter（tenant / role / risk / mcpServers）
        │
        ▼
 TagToolMatcher（profile + query 打分）──► Top-K 截断
        │
        ▼
 pinnedTools 合并（不占 Top-K）
        │
        ▼
 LazyToolMaterializer（只 materialize 命中的 Tool）
        │
        ▼
 Agent::addTool(ToolInterface[])
        │
        ▼
 LLM Tool Calling（与 Native / MCP 全量挂载链路相同）
```

**Native Tool** 指 Tool 在本地 PHP 定义和执行；**仍会走 LLM Tool Calling**，区别只是来源不是 MCP Server。

**MCP Tool** 通过 `McpFactory::tools()` 按 `only([$toolName])` 懒加载单个 Tool，复用 stdio 守卫、URL 白名单、进程限流。

---

## 配置

`neuron_ai.php` → `capability` 段：

```php
'capability' => [
    'enabled' => env('CAPABILITY_ENABLED', false),       // 总开关，默认关闭
    'default_top_k' => 12,                               // 每轮动态候选数
    'mcp_sync_on_boot' => true,                          // boot 时同步 MCP 元数据
    'max_schema_tools' => 20,                            // 注入 LLM 的 Tool 数兜底
    'fail_closed' => false,                              // false=出错回退旧 MCP 全量
],
```

环境变量见 `NeuronAiCapabilityEnv`（`CAPABILITY_ENABLED`、`CAPABILITY_DEFAULT_TOP_K` 等）。

### Native Tool 配置注册（可选）

```php
'capability' => [
    'native_tools' => [
        'native:rag:query_internal_kb' => [
            'name' => 'context_retrieval',
            'description' => 'Search internal knowledge base',
            'tags' => ['rag', 'knowledge', 'search'],
            'executor_ref' => 'native:rag:query_internal_kb',
        ],
    ],
],
```

---

## 快速上手

### 1. 通过 NeuronFactory 接入（推荐）

开启 Capability 后，在 `nodeConfig` 中声明参数；`mcpServers` 限制 MCP 来源，**不是**从配置文件自动读取，须由调用方传入。

未声明或传入空 `mcpServers` 时：**不会**同步 / 放行任何 MCP Tool（与旧 `attachMcpTools()` 一致）；仅 Native / pinned（且非 MCP）仍可入选。

```php
use Swoolefy\Support\Neuron\NeuronFactory;
use Swoolefy\Support\Mcp\McpComponentFactory;
use Swoolefy\Support\CapabilityCenter\CapabilityComponentFactory;
use Swoolefy\Support\Workflow\State\WorkflowState;

$mcpFactory = McpComponentFactory::factory();
$capabilityFactory = new CapabilityComponentFactory(
    mcpFactory: $mcpFactory,
    nativeFactories: [
        'native:rag:query_internal_kb' => fn () => $retrievalToolFactory->make('product_kb'),
    ],
);

$neuronFactory = new NeuronFactory(
    mcpFactory: $mcpFactory,
    capabilityFactory: $capabilityFactory,  // 生产建议 Worker 级单例复用
);

$agent = $neuronFactory->create(
    MyAgent::class,
    WorkflowState::fromInput(['message' => '查询订单状态']),
    [
        'capabilityEnabled' => true,
        'message' => '查询订单状态',
        'mcpServers' => ['github', 'filesystem'],
        'capabilityTopK' => 8,
        'capabilityProfile' => 'code_research',
        'profileTags' => ['code', 'search'],
        'pinnedTools' => ['native:rag:query_internal_kb'],
        'provider' => 'deepseek',
    ],
);

// 注入结果可通过 Agent::getTools() 查看
$toolNames = array_map(fn ($t) => $t->getName(), $agent->getTools());
```

**Agent 侧**：若走 Capability 动态注入，`tools()` 应返回空数组，由工厂挂载：

```php
final class MyAgent extends Agent
{
    protected function tools(): array
    {
        return [];  // Tool 由 NeuronFactory + CapabilityCenter 注入
    }
}
```

### 2. 仅解析（不请求 LLM）

```php
use Swoolefy\Support\CapabilityCenter\CapabilityComponentFactory;
use Swoolefy\Support\CapabilityCenter\Resolver\ToolResolveContext;

$center = (new CapabilityComponentFactory($mcpFactory))
    ->capabilityCenter(['github'], tenantId: 't1');

$tools = $center->resolveTools(new ToolResolveContext(
    query: '搜索 github 代码',
    agentId: 'demo',
    tenantId: 't1',
    userId: null,
    mcpServers: ['github'],
    capabilityProfile: 'code_research',
    profileTags: ['code'],
    topK: 5,
    pinnedToolIds: ['native:rag:query_internal_kb'],
));

foreach ($tools as $tool) {
    echo $tool->getName() . "\n";
}
```

### 3. Workflow AI 节点

`AINodeBuilder::mcp()` 写入 `nodeConfig['mcpServers']`，`AINode` 执行时传给 `NeuronFactory`：

```php
use Swoolefy\Support\AI\Builder\AINodeBuilder;

$node = AINodeBuilder::make('research')
    ->agent(ResearchAgent::class)
    ->mcp(['github', 'brave_search'])
    ->build(neuronFactory: $neuronFactory);

// nodeConfig 等效于：
// [
//     'agent' => ResearchAgent::class,
//     'mcpServers' => ['github', 'brave_search'],
// ]
```

开启 Capability 时，同样 `mcp(['github'])` 表示**限制 Resolver 的 MCP 来源**，不再全量挂载该 server 的全部 Tool。

Capability 相关 nodeConfig 键（数组或 Workflow 节点扩展）：

```php
[
    'capabilityEnabled' => true,
    'capabilityTopK' => 12,
    'capabilityProfile' => 'research',
    'profileTags' => ['research', 'code'],
    'pinnedTools' => ['native:rag:query_internal_kb'],
    'mcpOnly' => ['github' => ['search_code']],
    'mcpExclude' => ['github' => ['delete_repo']],
]
```

### 4. 注册 Native Descriptor + 工厂

```php
use Swoolefy\Support\CapabilityCenter\CapabilityDescriptor;
use Swoolefy\Support\CapabilityCenter\CapabilitySource;
use Swoolefy\Support\CapabilityCenter\CapabilityComponentFactory;
use Test\Module\Agent\Tool\WeatherTools;

$factory = new CapabilityComponentFactory(
    nativeFactories: [
        'native:weather:get_weather' => static fn () => WeatherTools::getWeather(),
        'native:weather:get_date' => static fn () => WeatherTools::getDate(),
    ],
    nativeDescriptors: [
        new CapabilityDescriptor(
            id: 'native:weather:get_weather',
            name: 'get_weather',
            description: 'Get weather of a location for a given date.',
            source: CapabilitySource::Native,
            tags: ['weather', 'city', 'forecast'],
            executorRef: 'native:weather:get_weather',
        ),
    ],
);
```

Descriptor ID 规则：

| 来源 | ID 示例 | executorRef |
|------|---------|-------------|
| MCP | `mcp:github:search_code` | `mcp:github` |
| Native | `native:weather:get_weather` | factory id 或 class |

---

## pinnedTools 说明

`pinnedTools` 是**保底注入**的 descriptor ID 列表：

- 无论 Top-K / tag 匹配是否选中，都会强制注入
- **不占** `topK` 名额
- 仍须通过 Policy 过滤（tenant / role / risk）
- 适合 RAG 检索、安全审计等**不能漏选**的核心 Tool

```php
'pinnedTools' => ['native:weather:get_date'],  // 即使 topK=1，也会额外注入 get_date
```

---

## HTTP 演示接口

项目内 `Test/Module/Agent/Controller/AgentCapabilityController.php` 提供两个接口：

```bash
# 仅解析，不请求 LLM
curl -X POST "http://localhost:9501/api/v1/agent/capability/resolve" \
  -H "Content-Type: application/json" \
  -d '{
    "message": "深圳天气怎么样？",
    "topK": 1,
    "pinnedTools": ["native:weather:get_date"]
  }'

# 完整链路：解析 → 注入 Agent → LLM 对话
curl -X POST "http://localhost:9501/api/v1/agent/capability/chat" \
  -H "Content-Type: application/json" \
  -d '{
    "message": "深圳今天天气怎么样？",
    "topK": 1,
    "pinnedTools": ["native:weather:get_date"],
    "provider": "deepseek"
  }'
```

演示装配见 `Test/Module/Agent/CapabilityWeatherDemo.php`、`CapabilityToolAgent.php`。

---

## 失败策略

| 场景 | 行为 |
|------|------|
| 单个 MCP Server sync 失败 | `SupportLog::warning`，跳过该 server |
| 单个 Tool materialize 失败 | warning，跳过该 Tool |
| pinned Tool materialize 失败 | warning，debug 日志更醒目 |
| CapabilityCenter 整体失败 | `fail_closed=false` 时回退 `McpFactory::tools()` 全量挂载 |
| Resolver 结果为空 | 不注入 Tool（pinned 仍尝试） |

---

## 生产建议

1. **默认关闭灰度**：先部署代码，`CAPABILITY_ENABLED=false`；再按节点 / 租户开启 `capabilityEnabled=true`。
2. **Worker 级单例**：将 `CapabilityComponentFactory` 注入 `NeuronFactory`，避免每次请求重复 MCP sync。
3. **核心 Tool 用 pinned**：RAG、审计等不能依赖 tag 匹配的工具加入 `pinnedTools`。
4. **不建表**：Phase 3 使用 `InMemoryCapabilityRegistry`，Worker reload 后重新 sync；无需数据库迁移。
5. **mcp_sync_on_boot**：仅对 `nodeConfig` 显式声明的非空 `mcpServers` 同步；空列表不会回退为全量 server。
6. **多租户 Registry**：存储键为 `(tenantId, id)`，不同租户 sync 同名 MCP tool 互不覆盖。

---

## 测试

```bash
composer test:capability
# 或
php src/Support/CapabilityCenter/Tests/CapabilityCenterTest.php
```

覆盖：Policy 过滤、Top-K + pinned、MCP only/exclude、懒加载 materialize、配置注册。

---

## Phase 3 边界（当前未实现）

- embedding / pgvector 语义检索
- Tool 级审计 / HITL / RateLimit
- 管理 API、OpenAPI 批量导入
- 分布式 Registry 一致性

详见 [docs/CapabilityTool.md](../../../docs/CapabilityTool.md) Phase 4 / Phase 5 规划。
