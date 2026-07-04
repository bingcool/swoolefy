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
 * 表结构见 SQLChatHistory（chat_history：thread_id + messages JSON）。
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
