# Neuron 基础设施（LLM / 记忆 / Embedding / HTTP）

封装 [Neuron AI](https://docs.neuron-ai.dev/) 在 Swoolefy 中的装配：Provider 工厂、会话记忆、协程 HTTP、Embedding。

- 配置：`Test/Config/neuron_ai.php`（复制到 `APP_PATH/config/neuron_ai.php`）
- 架构设计：[swoolefyAI.md](../../../docs/swoolefyAI.md) §4.7
- Chat History 文档：[Neuron Chat History](https://docs.neuron-ai.dev/agent/chat-history-and-memory)

---

## 目录结构

```
Neuron/
├── NeuronFactory.php            # boot/create Agent：Provider + MCP（不强制 Memory）
├── NeuronProviderFactory.php
├── Memory/
│   ├── ChatHistoryFactory.php       # Agent::chatHistory() 选用后端
│   ├── ChatHistoryPdoResolver.php   # 组件容器解析 PDO
│   ├── RedisChatHistory.php         # Redis 热存储
│   ├── HotChatHistoryInterface.php
│   ├── SqlChatHistoryArchive.php    # 可选：逐条冷归档
│   ├── MemoryFactory.php            # @deprecated
│   └── MemoryFactoryInterface.php   # @deprecated
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

### InMemory（默认，单次请求）

```php
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\ChatHistoryInterface;
use Swoolefy\Support\Neuron\Memory\ChatHistoryFactory;

final class ChatAgent extends Agent
{
    protected function chatHistory(): ChatHistoryInterface
    {
        return ChatHistoryFactory::inMemory(50000);
    }
}
```

### SQL 持久化多轮（Neuron SQLChatHistory）

```php
final class SqlPersistChatAgent extends Agent
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
$agent = $neuronFactory->boot(new SqlPersistChatAgent($threadId, $pdo), [
    'provider' => 'deepseek',
]);
```

表结构（Neuron 原生）：

```sql
CREATE TABLE IF NOT EXISTS chat_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  thread_id VARCHAR(255) NOT NULL,
  messages LONGTEXT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_thread_id (thread_id)
);
```

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
$agent = $factory->boot(new SqlPersistChatAgent($threadId, $pdo), ['provider' => 'deepseek']);
```

---

## 运行测试

```bash
composer test:neuron
```
