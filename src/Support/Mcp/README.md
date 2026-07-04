# MCP（Model Context Protocol）集成

将外部 MCP Server 的 Tools 接入 Neuron Agent，支持**静态配置**、**多租户仓储**与**本地 stdio 进程限流**。MCP 调用由 Neuron `McpConnector` 管理，默认作为 LLM 中间上下文，不写入 `WorkflowState`（除非 Agent 再走 structured）。

- 架构设计：[swoolefyAI.md](../../../docs/swoolefyAI.md) §4.11
- 配置：`Config/neuron_ai.php`（模版 `src/Stubs/neuron_ai.conf.stub.php`）→ `mcp.max_local_processes`
- 关联：`Support/Neuron`（`NeuronFactory` 挂载 tools）、`Support/AI`（`AINodeBuilder::mcp()`）

---

## 目录结构

```
Mcp/
├── McpFactory.php                         # connector / tools / listServers
├── McpServerConfig.php                    # 传输类型检测、公开信息脱敏
├── McpServerConfigRepositoryInterface.php
├── InMemoryMcpServerConfigRepository.php  # 多租户内存仓储（单测 / 演示）
├── McpProcessRunner.php                   # 本地 stdio 并发守卫
├── McpProcessLimitException.php
└── Tests/
```

---

## 核心原理

配置解析优先级（高 → 低）：

1. 构造函数静态 `$servers` map
2. `McpServerConfigRepository`（按 `tenantId`）
3. 均未命中 → `transport=disabled` stub（`tools()` 返回空，不抛错）

```
AINode.mcpServers ──► NeuronFactory ──► McpFactory::tools()
                                              │
                                    远程 HTTP/SSE 或本地 stdio
                                              │
                                    acquire/release（仅 stdio）
```

生产优先**远程 HTTP/SSE**；stdio 仅适合开发或内网脚本，受 `MCP_MAX_LOCAL_PROCESSES`（默认 2）限制。

---

## 快速上手

```php
use Swoolefy\Support\Mcp\McpFactory;
use Swoolefy\Support\Mcp\McpProcessRunner;
use Swoolefy\Support\Mcp\InMemoryMcpServerConfigRepository;

$factory = new McpFactory(
    servers: [
        'docs' => [
            'transport' => 'http',
            'url' => 'https://mcp.example.com/sse',
        ],
    ],
    repository: new InMemoryMcpServerConfigRepository(),
    processRunner: McpProcessRunner::fromEnv(),
);

// 未配置的 server → disabled stub，不阻断主流程
$connector = $factory->connector('missing');

// 批量发现 tools（失败吞掉，返回已成功部分）
$tools = $factory->tools(['docs'], only: ['docs' => ['search']]);

// API 列表（凭证脱敏）
$factory->listServers(tenantId: 't1');
```

### 在 AINode 上声明

```php
AINode::make('research')
    ->agent(ResearchAgent::class)
    ->mcp(['docs', 'github'], only: ['docs' => ['search']])
    ->build();
```

`NeuronFactory` 会在创建 Agent 时调用 `McpFactory::tools()` 并 `addTool`。

### 环境变量

| 变量 | 说明 |
|------|------|
| `MCP_MAX_LOCAL_PROCESSES` | 单 Worker 本地 stdio 最大并发（默认 2） |

---

## 运行测试

```bash
composer test:mcp
# 或
php src/Support/Mcp/Tests/McpModuleTest.php
```
