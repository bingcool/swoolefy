# AI 工作流节点与流式输出

把 Neuron Agent 封装为工作流节点：普通对话、**Structured Output**、流式 token、多 Agent 并行。引擎只依赖本模块接口，不直接依赖 neuron-ai。

- 架构设计：[swoolefyAI.md](../../../docs/swoolefyAI.md) §4.3、§4.8
- 关联：`Support/Agent`、`Support/Neuron`、`Support/Workflow`

---

## 目录结构

```
AI/
├── Builder/AINodeBuilder.php    # Fluent DSL
├── Node/
│   ├── AINode.php               # chat / structured / stream / executor
│   ├── StructuredOutputNode.php # structured 语义封装
│   └── AgentParallelNode.php    # 多 Agent 并行（委托 AgentScheduler）
├── Stream/
│   ├── StreamBridge.php         # 协程级 Sink 绑定
│   ├── StreamSinkInterface.php
│   ├── SseStreamSink.php        # HTTP SSE
│   ├── WebSocketStreamSink.php
│   ├── CollectingStreamSink.php # 单测收集 token
│   └── SseResponse.php
└── Tests/
```

---

## 核心原理

`AINode` 执行优先级：

1. **executor**（单测 / 自定义逻辑，不调 LLM）
2. **stream + agent** → `Agent::stream()`，token 经 `StreamBridge::emit`
3. **structured + agent** → `Agent::structured($dtoClass)`
4. **agent** → `Agent::chat()`

结果写入 `state.data[outputKey]`；对象会 `get_object_vars` 成数组，便于条件边读取。

**约束**：同一节点 **stream 与 structured 互斥**。

---

## 快速上手

### Structured Output + 条件边

```php
use Swoolefy\Support\AI\Node\AINode;
use Test\Module\Order\Dto\OrderDecisionDto;

$node = AINode::make('ai_decision')
    ->agent(OrderDecisionAgent::class)
    ->structured(OrderDecisionDto::class, outputKey: 'decision')
    ->memory(threadIdKey: 'sessionId')
    ->build();

// 单测注入 mock，无需 API Key：
$node = AINode::make('ai_decision')
    ->structured(OrderDecisionDto::class, outputKey: 'decision')
    ->executor(function ($ctx, $state) {
        $dto = new OrderDecisionDto();
        $dto->approved = true;
        $dto->confidence = 0.95;
        $dto->reason = 'ok';
        return $dto;
    })
    ->build();
```

等价写法：`new StructuredOutputNode('ai_decision', OrderDecisionDto::class, [...])`。

下游：

```php
// 条件边
EdgeCondition::when("data['decision'].approved == true")

// 节点内
$decision = $state->dto(OrderDecisionDto::class); // 需 registerSchema('decision', ...)
```

### Builder 常用 API

| 方法 | 说明 |
|------|------|
| `agent($class)` | Neuron Agent FQCN |
| `structured($dto, $outputKey)` | 结构化输出 |
| `stream(true)` | 流式（与 structured 互斥） |
| `memory($threadIdKey)` | 会话记忆 |
| `provider($alias)` | `neuron_ai.php` 中 Provider 别名 |
| `mcp($servers, $only, $exclude)` | 挂载 MCP Tools |
| `executor($fn)` | 跳过 LLM |
| `timeout($seconds)` | 节点超时 |

### 流式输出

```php
use Swoolefy\Support\AI\Stream\CollectingStreamSink;
use Swoolefy\Support\AI\Stream\StreamBridge;

// HTTP 请求协程内：
StreamBridge::bind(new CollectingStreamSink()); // 或 SseStreamSink
// ... 运行含 stream AINode 的工作流 ...
StreamBridge::unbind();
```

无协程 Context 时 `bind`/`emit` 为空操作，不抛错。

---

## 运行测试

```bash
composer test:ai
# 或
php src/Support/AI/Tests/AIModuleTest.php
```
