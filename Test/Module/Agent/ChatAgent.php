<?php

declare(strict_types=1);

namespace Test\Module\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Chat\History\ChatHistoryInterface;
use Swoolefy\Support\Neuron\Memory\ChatHistoryFactory;
use Test\Module\Agent\Concerns\ResolvesDefaultProvider;

/**
 * 简单对话 Agent —— 进程内 InMemory 记忆（由 chatHistory() 声明）。
 *
 * @see https://docs.neuron-ai.dev/agent/chat-history-and-memory
 */
final class ChatAgent extends Agent
{
    use ResolvesDefaultProvider;

    protected function chatHistory(): ChatHistoryInterface
    {
        return ChatHistoryFactory::inMemory(50000);
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
