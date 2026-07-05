# MCP（Model Context Protocol）集成

将外部 MCP Server 的 Tools 接入 Neuron Agent，支持**静态配置**、**DB 多租户仓储**、**stdio 生产禁用**与**出站 URL 白名单**。MCP 调用由 Neuron `McpConnector` 管理；加载失败会写入 `SupportLog` 而非静默吞掉。

- 架构设计：[swoolefyAI.md](../../../docs/swoolefyAI.md) §4.11
- 配置：`Config/neuron_ai.php`（模版 `src/Stubs/neuron_ai.conf.stub.php`）
- 关联：`Support/Neuron`（`NeuronFactory` 挂载 tools + `tenantId`）、`Support/AI`（`AINodeBuilder::mcp()`）

---

## 目录结构

```
Mcp/
├── McpFactory.php                         # connector / tools / listServers
├── McpServerConfig.php                    # 传输类型检测、公开信息脱敏
├── McpServerConfigRepositoryInterface.php
├── InMemoryMcpServerConfigRepository.php  # 多租户内存仓储（单测 / 演示）
├── DbMcpServerConfigRepository.php        # DB 仓储（须预执行 Schema/mcp_server_configs.sql）
├── McpStdioGuard.php                      # 生产禁用 stdio / 命令 allowlist
├── McpProcessRunner.php                   # 本地 stdio 并发守卫
├── McpProcessLimitException.php
├── Schema/mcp_server_configs.sql
└── Tests/
```

---

## 核心原理

配置解析优先级（高 → 低）：

1. `Repository` 租户专属（`tenantId` 非空时）
2. `Repository` 全局（`tenant_id` 为空）
3. 构造函数静态 `$servers` map
4. 均未命中 → `transport=disabled` stub（`tools()` 返回空，不抛错）

```
AINode.mcpServers ──► NeuronFactory（tenantId / FrameworkContext）
                              │
                              ▼
                      McpFactory::tools()
                              │
                    McpStdioGuard + OutboundUrlGuard
                              │
                    远程 HTTP/SSE 或本地 stdio
                              │
                    acquire/release（仅 stdio）
```

`NeuronFactory::attachMcpTools()` 按以下顺序解析 `tenantId`：节点配置 `tenantId` → `FrameworkContext::getTenantId()`。

生产优先**远程 HTTP/SSE**；stdio 默认禁用（`mcp.allow_stdio=false`），开发可 `MCP_ALLOW_STDIO=1` 并配置 `stdio_command_allowlist`。

---

## 快速上手

```php
use Swoolefy\Support\Mcp\DbMcpServerConfigRepository;
use Swoolefy\Support\Mcp\McpFactory;
use Swoolefy\Support\Mcp\McpProcessRunner;

$factory = new McpFactory(
    servers: [
        'docs' => [
            'transport' => 'http',
            'url' => 'https://mcp.example.com/sse',
        ],
    ],
    repository: new DbMcpServerConfigRepository($pdo),
    processRunner: McpProcessRunner::fromEnv(),
);

// 租户隔离
$tools = $factory->tools(['docs'], tenantId: 'tenant_a');

// API 列表（凭证脱敏）
$factory->listServers(tenantId: 'tenant_a');
```

### 在 AINode 上声明

```php
AINode::make('research')
    ->agent(ResearchAgent::class)
    ->mcp(['docs', 'github'], only: ['docs' => ['search']])
    ->build();

// 或在节点 config 中指定 tenantId
AINode::make('research')
    ->agent(ResearchAgent::class)
    ->mcp(['docs'])
    ->build(); // NeuronFactory 自动读取 FrameworkContext::getTenantId()
```

### 环境变量

| 变量 | 说明 |
|------|------|
| `MCP_MAX_LOCAL_PROCESSES` | 单 Worker 本地 stdio 最大并发（默认 2） |
| `MCP_ALLOW_STDIO` | 是否允许 stdio MCP（生产默认 `0`） |
| `NEURON_ALLOW_PRIVATE_NETWORKS` | 是否允许出站 URL 指向私网（默认 `0`） |

### 安全（Phase B）

- **stdio**：`McpStdioGuard` 在生产环境拦截本地 stdio；启用时命令须在 `stdio_command_allowlist` 内。
- **出站 URL**：`security.outbound_url_allowlist` 限制 MCP / LLM Provider 的 host；空数组时仍拦截 loopback / 私网（除非 `allow_private_networks=true`）。

### DB 仓储

使用前执行 `Schema/mcp_server_configs.sql`。`DbMcpServerConfigRepository` 支持按 `tenant_id` 隔离；全局行 `tenant_id` 为空，对所有租户可见（优先级低于租户专属行）。

---

## 运行测试

```bash
composer test:mcp
composer test:phase-b
# 或
php src/Support/Mcp/Tests/McpModuleTest.php
```
