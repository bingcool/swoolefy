<?php

declare(strict_types=1);

namespace Test\Module\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Chat\History\ChatHistoryInterface;
use PDO;
use Swoolefy\Support\Neuron\Memory\ChatHistoryFactory;
use Test\Module\Agent\Concerns\ResolvesDefaultProvider;

/**
 * 对话 Agent —— chatHistory() 使用 Neuron SQLChatHistory 持久化多轮会话。
 *
 * 表结构见 Schema/chat_history.sql（tenant_id + thread_id 唯一，messages JSON）。
 * 使用前须执行 Schema/chat_history.sql 建表。
 *
 * @see https://docs.neuron-ai.dev/agent/chat-history-and-memory
 */
final class ChatAgent extends Agent
{
    use ResolvesDefaultProvider;

    public function __construct(
        private readonly string $threadId,
        private readonly PDO $pdo,
        private readonly int $contextWindow = 50000,
        private readonly string $table = 'chat_history',
        private readonly ?string $tenantId = null,
        private readonly ?string $userId = null,
    ) {
        parent::__construct();
    }

    protected function chatHistory(): ChatHistoryInterface
    {
        return ChatHistoryFactory::sql(
            threadId: $this->threadId,
            pdo: $this->pdo,
            table: $this->table,
            contextWindow: $this->contextWindow,
            tenantId: $this->tenantId,
            userId: $this->userId,
        );
    }

    protected function instructions(): string
    {
        return (string) new SystemPrompt(
            background: [
                'You are a helpful assistant in a Swoolefy demo application.',
                'Reply clearly and concisely in the same language as the user.',
            ],
        );
    }
}
