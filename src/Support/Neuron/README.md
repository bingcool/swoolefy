# Neuron 基础设施（LLM / 记忆 / Embedding / HTTP）

封装 [Neuron AI](https://docs.neuron-ai.dev/) 在 Swoolefy 中的装配：Provider 工厂、会话记忆、协程 HTTP、Embedding。

- 配置：`Config/neuron_ai.php`（模版 `src/Stubs/neuron_ai.conf.stub.php`，`create` 命令自动复制）
- 架构设计：[swoolefyAI.md](../../../docs/swoolefyAI.md) §4.7
- Chat History 文档：[Neuron Chat History](https://docs.neuron-ai.dev/agent/chat-history-and-memory)

---

## 目录结构

```
Neuron/
├── NeuronFactory.php            # boot/create Agent：Provider + MCP（不强制 Memory）
├── NeuronProviderFactory.php
├── Schema/
│   ├── chat_history.sql         # SQLChatHistory 热存储（使用前须建表）
│   └── chat_messages.sql        # SqlChatHistoryArchive 冷归档（使用前须建表）
├── Memory/
│   ├── ChatHistoryFactory.php       # Agent::chatHistory() 选用后端
│   ├── ChatHistoryPdoResolver.php   # 组件容器解析 PDO
│   ├── RedisChatHistory.php         # Redis 热存储
│   ├── HotChatHistoryInterface.php
│   └── SqlChatHistoryArchive.php    # 可选：逐条冷归档
├── Http/
├── Embedding/
└── Tests/
```

---

## 核心原理

```
业务 Agent
  protected function chatHistory(): ChatHistoryInterface
      └─ ChatHistoryFactory::inMemory() | sql() | redis() | file()

NeuronFactory::create / boot
  ├─ applyProvider（节点 provider 别名或 default）
  ├─ setChatHistory（仅当 nodeConfig['chatHistory'] 显式传入）
  └─ addTool（mcpServers）
```

会话记忆由 **Agent 自身** 在 `chatHistory()` 中声明，扩展性更强，与 [Neuron 官方模式](https://docs.neuron-ai.dev/agent/chat-history-and-memory) 一致。

---

## 业务 Agent 示例

### InMemory（单次请求，无持久化）

```php
final class EphemeralChatAgent extends Agent
{
    protected function chatHistory(): ChatHistoryInterface
    {
        return ChatHistoryFactory::inMemory(50000);
    }
}
```

### SQL 持久化多轮（Neuron SQLChatHistory）

**使用前必须先执行** `Schema/chat_history.sql` 创建 `chat_history` 表。

```php
final class ChatAgent extends Agent
{
    public function __construct(
        private readonly string $threadId,
        private readonly \PDO $pdo,
    ) {
        parent::__construct();
    }

    protected function chatHistory(): ChatHistoryInterface
    {
        return ChatHistoryFactory::sql($this->threadId, $this->pdo);
    }
}

// Controller
$pdo = ChatHistoryPdoResolver::resolve('db');
$agent = $neuronFactory->boot(new ChatAgent($threadId, $pdo), [
    'provider' => 'deepseek',
]);
```

建表脚本：`src/Support/Neuron/Schema/chat_history.sql`

```sql
-- 见 Schema/chat_history.sql（thread_id 唯一，messages 为 JSON）
```

### SQL 冷归档（逐条消息，可选）

**使用前必须先执行** `Schema/chat_messages.sql` 创建 `chat_messages` 表。

```php
$archive = new SqlChatHistoryArchive($pdo);
$archive->archiveMessage($threadId, 'user', 'Hello');
$messages = $archive->listMessages($threadId, 50);
```

建表脚本：`src/Support/Neuron/Schema/chat_messages.sql`

### Redis

```php
protected function chatHistory(): ChatHistoryInterface
{
    return ChatHistoryFactory::redis($this->threadId, $redisConnection);
}
```

---

## NeuronFactory

```php
// 无参 Agent
$agent = $factory->create(ChatAgent::class, $state, ['provider' => 'openai']);

// 带构造参数的 Agent（自行决定 chatHistory）
$agent = $factory->boot(new ChatAgent($threadId, $pdo), ['provider' => 'deepseek']);
```

---

## 运行测试

```bash
composer test:neuron
```
