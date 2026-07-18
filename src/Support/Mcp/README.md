# MCP（Model Context Protocol）集成

将外部 MCP Server 的 Tools 接入 Neuron Agent，支持**静态配置**、**DB 全局仓储**、**stdio 生产禁用**与**出站 URL 白名单**。MCP 调用由 Neuron `McpConnector` 管理；加载失败会写入 `SupportLog` 而非静默吞掉。

- 架构设计：[SwoolefyAI.md](../../../docs/SwoolefyAI.md) §4.11
- 配置：`Config/neuron_ai.php`（模版 `src/Stubs/neuron_ai.conf.stub.php`）
- 关联：`Support/Neuron`（`NeuronFactory` 挂载 tools）、`Support/AI`（`AINodeBuilder::mcp()`）

---

## 目录结构

```
Mcp/
├── McpFactory.php                         # connector / tools / listServers
├── McpComponentFactory.php                # 从 neuron_ai.php + db_component 装配
├── McpPdoResolver.php                     # 解析 database.php 组件 PDO
├── McpServerConfig.php                    # 传输类型检测、公开信息脱敏
├── McpServerConfigRepositoryInterface.php
├── InMemoryMcpServerConfigRepository.php  # 内存仓储（单测 / 演示）
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

1. `Repository` 行（生产 DB 表为全局基础配置）
2. 构造函数静态 `$servers` map
3. 均未命中 → `transport=disabled` stub（`tools()` 返回空，不抛错）

```
AINode.mcpServers ──► NeuronFactory
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

生产优先**远程 HTTP/SSE**；stdio 默认禁用（`mcp.allow_stdio=false`），开发可 `MCP_ALLOW_STDIO=1` 并配置 `stdio_command_allowlist`。

---

## 快速上手

```php
use Swoolefy\Support\Mcp\McpComponentFactory;
use Swoolefy\Support\Mcp\McpFactory;
use Swoolefy\Support\Mcp\McpProcessRunner;

// 生产：从 neuron_ai.php mcp.db_component 解析 PDO 并装配 DB 仓储
$factory = McpComponentFactory::factory();

// 或手动注入 PDO（单测 / 脚本）
$factory = new McpFactory(
    repository: McpComponentFactory::dbRepository(),
    processRunner: McpProcessRunner::fromEnv(),
);

$tools = $factory->tools(['docs']);

// API 列表（凭证脱敏）
$factory->listServers();
```

### 在 AINode 上声明

```php
AINode::make('research')
    ->agent(ResearchAgent::class)
    ->mcp(['docs', 'github'], only: ['docs' => ['search']])
    ->build();
```

### 环境变量

| 变量 | 说明 |
|------|------|
| `MCP_MAX_LOCAL_PROCESSES` | 单 Worker 本地 stdio 最大并发（默认 2） |
| `MCP_ALLOW_STDIO` | 是否允许 stdio MCP（生产默认 `0`） |
| `MCP_DATABASE_COMPONENT` | MCP 配置表使用的 `database.php` 组件别名（默认 `db`） |
| `NEURON_ALLOW_PRIVATE_NETWORKS` | 是否允许出站 URL 指向私网（默认 `0`） |

### 安全（Phase B）

- **stdio**：`McpStdioGuard` 在生产环境拦截本地 stdio；启用时命令须在 `stdio_command_allowlist` 内。
- **出站 URL**：`security.outbound_url_allowlist` 限制 MCP / LLM Provider 的 host；空数组时仍拦截 loopback / 私网（除非 `allow_private_networks=true`）。

### DB 仓储

使用前执行 `Schema/mcp_server_configs.sql`。`DbMcpServerConfigRepository` 通过 `mcp.db_component`（`MCP_DATABASE_COMPONENT`）从 `Config/component/database.php` 解析 PDO；读取全局基础配置表，按 `server_id` 唯一。

---

## 运行测试

```bash
composer test:mcp
composer test:phase-b
# 或
composer test:mcp
# 或 ./vendor/bin/phpunit --filter McpModuleTest
```
