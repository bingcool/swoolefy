<?php

declare(strict_types=1);

namespace Swoolefy\Support\Neuron\Memory;

use NeuronAI\Chat\History\ChatHistoryInterface;

/**
 * @deprecated 请在业务 Agent 中实现 chatHistory()，并通过 {@see ChatHistoryFactory} 选择后端。
 *
 * @see https://docs.neuron-ai.dev/agent/chat-history-and-memory
 */
final class MemoryFactory implements MemoryFactoryInterface
{
    /** {@inheritdoc} */
    public function forThread(string $threadId, int $contextWindow = 50000): ChatHistoryInterface
    {
        return ChatHistoryFactory::inMemory($contextWindow);
    }
}
