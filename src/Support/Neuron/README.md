# Neuron 基础设施（LLM / 记忆 / Embedding / HTTP）

封装 [Neuron AI](https://docs.neuron-ai.dev/) 在 Swoolefy 中的装配：Provider 工厂、会话记忆、协程 HTTP、Embedding。上层 `AINode` / `RagFactory` 通过本模块获取能力，避免业务代码直接拼 Provider。

- 配置：`Test/Config/neuron_ai.php`（复制到 `APP_PATH/config/neuron_ai.php`）
- 架构设计：[swoolefyAI.md](../../../docs/swoolefyAI.md) §4.7
- 关联：`Support/AI`、`Support/Agent`、`Support/Rag`、`Support/Mcp`

---

## 目录结构

```
Neuron/
├── NeuronFactory.php            # 创建 Agent：Provider + Memory + MCP Tools
├── NeuronProviderFactory.php    # ai_model_providers 反射构造
├── NeuronAiConfig.php           # neuron_ai.php 加载器
├── NeuronAiProviderName.php    # Provider 别名常量
├── NeuronAiModelEnv.php         # API Key / Model 环境变量名
├── NeuronAiVectorStoreName.php  # RAG 驱动名（与 Rag 共用）
├── NeuronAiRagEnv.php           # RAG 环境变量名
├── Http/
│   ├── NeuronHttpFactory.php    # swoole | guzzle
│   └── SwooleHttpClientAdapter.php
├── Memory/
│   ├── MemoryFactory.php        # Redis 热记忆 / InMemory 回退
│   ├── RedisChatHistory.php
│   └── SqlChatHistoryArchive.php
├── Embedding/
│   ├── EmbeddingFactory.php     # OpenAI-like / FakeEmbeddings
│   └── SwooleOpenAILikeEmbeddings.php
└── Tests/
```

---

## 核心原理

```
neuron_ai.php
  neuron.ai_model_providers[alias] ──► NeuronProviderFactory ──► AIProviderInterface
  neuron.default_provider
  neuron.http_client (swoole|guzzle)
  rag.* / mcp.*

NeuronFactory::create(AgentClass, state, nodeConfig)
  ├─ applyProvider（节点 provider 别名或 default）
  ├─ setChatHistory（memory=true 时）
  └─ addTool（mcpServers 时经 McpFactory）
```

除 `provider`（FQCN）外，`ai_model_providers` 的键名与对应 Provider **构造函数参数一致**。

---

## 快速上手

### 配置 Provider

```php
// neuron_ai.php
'neuron' => [
    'http_client' => 'swoole',
    'default_provider' => NeuronAiProviderName::OPENAI,
    'ai_model_providers' => [
        NeuronAiProviderName::OPENAI => [
            'provider' => OpenAI::class,
            'key' => env(NeuronAiModelEnv::OPENAI_API_KEY),
            'model' => env(NeuronAiModelEnv::OPENAI_MODEL),
        ],
    ],
],
```

### 创建 Agent

```php
use Swoolefy\Support\Neuron\NeuronFactory;
use Swoolefy\Support\Neuron\Memory\MemoryFactory;

$factory = new NeuronFactory(new MemoryFactory());
$agent = $factory->create(MyAgent::class, $state, [
    'memory' => true,
    'threadIdKey' => 'sessionId',
    'provider' => 'openai',
    'model' => 'gpt-4o-mini', // 覆盖配置中的 model
]);
```

Agent 若已实现自定义 `provider()`，且节点未指定 `provider`，则不会强行注入 default。

### 会话记忆 threadId 策略

| 模式 | threadId 示例 |
|------|----------------|
| 用户长期记忆 | `{userId}:{agentName}` |
| 匿名会话 | `{sessionId}` |
| Run 隔离 | `{userId}:{workflowId}:{runId}` |

无 Redis 时 `MemoryFactory` 回退 `InMemoryChatHistory`（仅当前进程有效）。

### Embedding

```php
use Swoolefy\Support\Neuron\Embedding\EmbeddingFactory;

$embedder = (new EmbeddingFactory())->make();
// 有 OPENAI_API_KEY → SwooleOpenAILikeEmbeddings
// 无 Key → FakeEmbeddingsProvider（单测 / 本地）
```

模型名来自 `rag.embedding_model` / `RAG_EMBEDDING_MODEL`。

### HTTP 客户端

| `neuron.http_client` | 说明 |
|----------------------|------|
| `swoole`（默认） | 协程 CurlProxy；CLI 无 `APP_PATH` 时回退 Guzzle |
| `guzzle` | 同步 Guzzle |

---

## 常用环境变量

| 变量 | 说明 |
|------|------|
| `OPENAI_API_KEY` | LLM / Embedding |
| `NEURON_DEFAULT_PROVIDER` | 默认 Provider 别名 |
| `NEURON_HTTP_CLIENT` | `swoole` / `guzzle` |
| `ANTHROPIC_API_KEY` 等 | 见 `NeuronAiModelEnv` |

---

## 运行测试

```bash
composer test:neuron
# 或
php src/Support/Neuron/Tests/NeuronModuleTest.php
```
